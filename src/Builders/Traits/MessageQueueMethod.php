<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use RedisException;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;
use Workbunny\WebmanRqueue\Headers;
use Workerman\Worker;

trait MessageQueueMethod
{
    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueFullInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        return $this->getConnection()->client()->rawCommand('XINFO', 'STREAM', $queueName, 'FULL');
    }

    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        return $this->getConnection()->client()->xInfo('STREAM', $queueName);
    }

    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueConsumersInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        $groupName = $this->getBuilderConfig()->getGroup();
        return $this->getConnection()->client()->xInfo('CONSUMERS', $queueName, $groupName);
    }

    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueGroupsInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        $groupName = $this->getBuilderConfig()->getGroup();
        return $this->getConnection()->client()->xInfo('GROUPS', $queueName, $groupName);
    }

    /**
     * @return void
     * @throws RedisException
     */
    public function del(): void
    {
        if($groups = $this->getQueueGroupsInfo() and $info = $this->getQueueInfo()) {
            $firstId = array_keys($info['first-entry'])[0];
            $lastDeliveredId = $groups['last-delivered-id'];
            $queueName = $this->getBuilderConfig()->getQueue();
            $client = $this->getConnection()->client();
            $result = $client->xRange($queueName, $firstId, $lastDeliveredId, 100);
            foreach ($result as $id => $value) {
                if($id !== $lastDeliveredId) {
                    $client->xDel($queueName, [$id]);
                }
            }
        }

    }

    /**
     * @param string $body
     * @param array $headers = [
     *  @see Headers
     * ]
     * @return bool
     * @throws WebmanRqueueException
     */
    public function publish(string $body, array $headers = []): bool
    {
        $client = $this->getConnection()->client();
        $header = new Headers($headers);
        if(
            ($header->_delay and !$this->getBuilderConfig()->isDelayed()) or
            (!$header->_delay and $this->getBuilderConfig()->isDelayed())
        ){
            throw new WebmanRqueueException('Invalid publish. ');
        }
        $queue = $this->getBuilderConfig()->getQueue();
        $queueSize = $this->getBuilderConfig()->getQueueSize();
        try {
            if($queueSize > 0) {
                $queueLen = $client->xLen($queue);
                if($queueLen >= $queueSize){
                    return false;
                }
            }
            return (bool) $client->xAdd($queue, (string)$header->_id, [
                '_header' => $header->toString(),
                '_body'   => $body,
            ]);
        }catch (RedisException $exception) {
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
            $queueName = $builderConfig->getQueue();
            $groupName = $builderConfig->getGroup();
            $consumerName = "$groupName-$worker->id";
            // create group
            $client->xGroup('CREATE', $queueName, $groupName,'0', true);
            // group read
            if($res = $client->xReadGroup(
                $groupName, $consumerName, [$queueName => '>'], $builderConfig->getPrefetchCount(),
                $this->timerInterval >= 1 ? $this->timerInterval : null
            )) {
                $ids = [];
                // messages
                foreach ($res[$queueName] ?? [] as $id => $message){
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
        }catch (RedisException $exception) {
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}