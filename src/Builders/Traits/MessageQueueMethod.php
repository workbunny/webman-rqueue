<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use RedisException;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;
use Workbunny\WebmanRqueue\Headers;
use Workerman\Worker;

trait MessageQueueMethod
{
    /**
     * @param string|null $queueName
     * @return array
     * @throws RedisException
     */
    public function getQueueFullInfo(null|string $queueName = null): array
    {
        $queues = $queueName ? [$queueName] : $this->getBuilderConfig()->getQueues();
        $result = [];
        foreach ($queues as $queue) {
            $result[$queue] = $this->getConnection()->client()->rawCommand('XINFO', 'STREAM', $queue, 'FULL');
        }
        return $result;
    }

    /**
     * @param string|null $queueName
     * @return array
     * @throws RedisException
     */
    public function getQueueInfo(null|string $queueName = null): array
    {
        $queues = $queueName ? [$queueName] : $this->getBuilderConfig()->getQueues();
        $result = [];
        foreach ($queues as $queue) {
            $result[$queue] = $this->getConnection()->client()->xInfo('STREAM', $queue);
        }
        return $result;
    }

    /**
     * @param string|null $queueName
     * @return array
     * @throws RedisException
     */
    public function getQueueConsumersInfo(null|string $queueName = null): array
    {
        $groupName = $this->getBuilderConfig()->getGroup();
        $queues = $queueName ? [$queueName] : $this->getBuilderConfig()->getQueues();
        $result = [];
        foreach ($queues as $queue) {
            $result[$queue] = $this->getConnection()->client()->xInfo('CONSUMERS', $queueName, $groupName);
        }
        return $result;
    }

    /**
     * @param string|null $queueName
     * @return array
     * @throws RedisException
     */
    public function getQueueGroupsInfo(null|string $queueName = null): array
    {
        $groupName = $this->getBuilderConfig()->getGroup();
        $queues = $queueName ? [$queueName] : $this->getBuilderConfig()->getQueues();
        $result = [];
        foreach ($queues as $queue) {
            $result[$queue] = $this->getConnection()->client()->xInfo('GROUPS', $queue, $groupName);
        }
        return $result;
    }

    /**
     * @return void
     * @throws RedisException
     */
    public function del(): void
    {
        $groups = $this->getQueueGroupsInfo();
        $info = $this->getQueueInfo();
        if($groups and $info) {
            $queues = $this->getBuilderConfig()->getQueues();
            $client = $this->getConnection()->client();
            foreach ($queues as $queue) {
                $firstId = $info[$queue]['first-entry'][0] ?? null;
                $lastDeliveredId = $groups[$queue]['last-delivered-id'] ?? null;
                if($firstId and $lastDeliveredId) {
                    $result = $client->xRange($queue, $firstId, $lastDeliveredId, 100);
                    foreach ($result as $id => $value) {
                        if($id !== $lastDeliveredId) {
                            $client->xDel($queue, [$id]);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $body
     * @param array $headers = [
     *  @see Headers
     * ]
     * @param string|null $queueName
     * @return int|false
     */
    public function publish(string $body, array $headers = [], null|string $queueName = null): int|false
    {
        $client = $this->getConnection()->client();
        $header = new Headers($headers);
        $header->_timestamp = $header->_timestamp > 0.0 ? $header->_timestamp : microtime(true);
        if(
            ($header->_delay and !$this->getBuilderConfig()->isDelayed()) or
            (!$header->_delay and $this->getBuilderConfig()->isDelayed())
        ){
            throw new WebmanRqueueException('Invalid publish.');
        }
        $queues = $this->getBuilderConfig()->getQueues();
        $queueSize = $this->getBuilderConfig()->getQueueSize();
        if($queueName !== null and !isset($queues[$queueName])) {
            throw new WebmanRqueueException('Invalid queue name.');
        }
        $queues = $queueName ? [$queueName] : $queues;
        $count = 0;
        try {
            foreach ($queues as $queue) {
                if($queueSize > 0) {
                    $queueLen = $client->xLen($queue);
                    if($queueLen >= $queueSize){
                        throw new WebmanRqueueException('Queue size exceeded.');
                    }
                }
                if(!$client->xAdd($queue, (string)$header->_id, [
                    '_header' => $header->toString(),
                    '_body'   => $body,
                ])) {
                    return false;
                }
                $count ++;
            }
            return $count;
        }catch (RedisException $exception) {
            $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param Worker $worker
     * @param bool $del
     * @return void
     * @throws WebmanRqueueException
     */
    public function consume(Worker $worker, bool $del = true): void
    {
        try {
            $client = $this->getConnection()->client();
            $builderConfig = $this->getBuilderConfig();
            $queues = $builderConfig->getQueues();
            $groupName = $builderConfig->getGroup();
            $consumerName = "$groupName-$worker->id";
            // create group
            $queueStreams = [];
            foreach ($queues as $queueName) {
                $client->xGroup('CREATE', $queueName, $groupName,'0', true);
                $queueStreams[$queueName] = '>';
            }
            // group read
            if($res = $client->xReadGroup(
                $groupName, $consumerName, $queueStreams, $builderConfig->getPrefetchCount(),
                $this->timerInterval >= 1.0 ? (int)$this->timerInterval : null
            )) {
                foreach ($res as $queueName => $item) {
                    $ids = [];
                    // messages
                    foreach ($item as $id => $message){
                        // drop
                        if(!isset($message['_header']) or !isset($message['_body'])) {
                            $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                            continue;
                        }
                        $header = new Headers($message['_header']);
                        $body = $message['_body'];
                        // delay message
                        if(
                            $this->getBuilderConfig()->isDelayed() and $header->_delay > 0 and
                            (($header->_delay / 1000 + $header->_timestamp) - microtime(true)) > 0
                        ){
                            // republish
                            $header->_id = '*';
                            $this->publish($body, $header->toArray());
                            $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                            continue;
                        }
                        try {
                            // handler
                            if(!\call_user_func($this->getBuilderConfig()->getCallback(), $id, $message, $this->getConnection())) {
                                // false to republish
                                $header->_count = $header->_count + 1;
                                $header->_id    = '*';
                                $this->publish($body, $header->toArray());
                            }
                            $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                        }catch (\Throwable $throwable) {
                            $header->_count = $header->_count + 1;
                            $header->_error = $throwable->getMessage();
                            $header->_id    = '*';
                            $this->publish($body, $header->toArray());
                            $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                        }
                    }
                    // del
                    if($del) { $client->xDel($queueName, $ids); }
                }
            }
        }catch (RedisException $exception) {
            $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}