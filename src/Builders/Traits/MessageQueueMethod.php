<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use RedisException;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;
use Workbunny\WebmanRqueue\Headers;
use Workerman\Worker;

trait MessageQueueMethod
{
    use MessageTempMethod;

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
     * @param string $queueName
     * @param string $groupName
     * @param array $id
     * @return bool
     */
    public function ack(string $queueName, string $groupName, array $id): bool
    {
        try {
            $this->getConnection()->client()->xAck($queueName, $groupName, $id);
            return true;
        } catch (RedisException) {
            $this->getLogger()?->warning('Ack failed. ', [
                'queue' => $queueName, 'group' => $groupName, 'id' => $id
            ]);
        }
        return false;
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
                if ($queueSize > 0) {
                    $queueLen = $client->xLen($queue);
                    if($queueLen >= $queueSize){
                        throw new WebmanRqueueException('Queue size exceeded.');
                    }
                }
                if (!$client->xAdd($queue, (string)$header->_id, [
                    '_header' => $header->toString(),
                    '_body'   => $body,
                ])) {
                    return false;
                }
                $count ++;
            }
            return $count;
        } catch (RedisException $exception) {
            $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param Worker $worker
     * @param int $pendingTimeout
     * @param bool $autoDel
     * @return void
     */
    public function claim(Worker $worker, int $pendingTimeout, bool $autoDel = true): void
    {
        try {
            $client = $this->getConnection()->client();
            $builderConfig = $this->getBuilderConfig();
            $queues = $builderConfig->getQueues();
            $groupName = $builderConfig->getGroup();
            $consumerName = "$groupName-$worker->id";
            foreach ($queues as $queueName) {
                if ($datas = $client->xAutoClaim(
                    $queueName, $groupName, $consumerName,
                    $pendingTimeout * 1000,
                    '0-0', -1
                )) {
                    if ($client->xAck($queueName, $groupName, $datas)) {
                        // pending超时的消息自动ack，并存入本地缓存
                        try {
                            foreach ($datas as $value) {
                                $this->tempInsert($queueName, $value);
                            }
                        }
                        // 忽略失败
                        catch (\Throwable) {}

                        if ($autoDel) {
                            // 移除
                            $client->xDel($queueName, array_keys($datas));
                        }
                    }
                }
            }

        } catch (RedisException $exception) {
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
                            $this->ack($queueName, $groupName, $this->idsAdd($ids, $id));
                            continue;
                        }
                        $header = new Headers($message['_header']);
                        $body = $message['_body'];
                        // delay message
                        if(
                            $this->getBuilderConfig()->isDelayed() and $header->_delay > 0 and
                            (($header->_delay / 1000 + $header->_timestamp) - microtime(true)) > 0
                        ){
                            // ack
                            if ($this->ack($queueName, $groupName, $this->idsAdd($ids, $id))) {
                                // republish
                                $header->_id = '*';
                                $this->publish($body, $header->toArray());
                            }
                            continue;
                        }
                        try {
                            // handler
                            if (!\call_user_func($this->getBuilderConfig()->getCallback(), $id, $message, $this->getConnection())) {
                                throw new WebmanRqueueException('Consume failed. ');
                            }
                            $this->ack($queueName, $groupName, $this->idsAdd($ids, $id));
                        } catch (\Throwable $throwable) {
                            if ($this->ack($queueName, $groupName, $this->idsAdd($ids, $id))) {
                                // republish
                                $header->_count = $header->_count + 1;
                                $header->_error = $throwable->getMessage();
                                $header->_id    = '*';
                                $this->publish($body, $header->toArray());
                            }
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