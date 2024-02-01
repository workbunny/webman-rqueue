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
            $result[$queue] = $this->getConnection()->client()->xInfo('STREAM', $queue, 'FULL');
        }
        return $result;
    }

    /**
     * 获取Builder中所有队列的信息
     *
     * @param string|null $queueName
     * @return array = [
     *  queueName => [
     *      'length' => int,
     *      'radix-tree-keys' => int,
     *      'radix-tree-nodes' => int,
     *      'last-generated-id' => string [int-int],
     *      'max-deleted-entry-id' => string [int-int],
     *      'entries-added' => int,
     *      'groups' => int,
     *      'first-entry' => [
     *          ID => [
     *              '_header' => array,
     *              '_body'   => string
     *          ]
     *      ],
     *      'last-entry' => [
     *          ID => [
     *              '_header' => array,
     *              '_body'   => string
     *          ]
     *      ]
     *  ]
     * ]
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
     * 获取Builder中所有队列的消费组信息
     *
     * @param string|null $queueName
     * @return array = [
     *  queueName => [
     *      [
     *          'name' => string,
     *          'consumers' => int,
     *          'pending' => int,
     *          'last-delivered-id' => string [int-int],
     *          'entries-read' => int,
     *          'lag' => int
     *      ]
     *  ]
     * ]
     * @throws RedisException
     */
    public function getQueueGroupsInfo(null|string $queueName = null): array
    {
        $queues = $queueName ? [$queueName] : $this->getBuilderConfig()->getQueues();
        $result = [];
        foreach ($queues as $queue) {
            $result[$queue] = $this->getConnection()->client()->xInfo('GROUPS', $queue);
        }
        return $result;
    }

    /**
     * 仅移除所有分组中游标最落后的至第一个消息间的消息
     *
     * @return void
     * @throws RedisException
     */
    public function del(): void
    {
        $groupsInfo = $this->getQueueGroupsInfo();
        $queuesInfo = $this->getQueueInfo();
        $client = $this->getConnection()->client();
        foreach ($groupsInfo as $queueName => $info) {
            $queueFirstId = array_key_first($queuesInfo[$queueName]['first-entry'] ?? []);
            $lastDeliveredIds = array_column($info, 'last-delivered-id');
            if ($queueFirstId and $lastDeliveredIds) {
                $leftMin = $rightMin = null;
                // 获取当前队列所有group中最落后的游标id
                foreach ($lastDeliveredIds as $lastDeliveredId) {
                    list($left, $right) = explode('-', $lastDeliveredId);
                    if (
                        ($left > $leftMin and $leftMin !== null) or
                        ($left == $leftMin and $right > $rightMin and $leftMin !== null)
                    ) {
                        continue;
                    }
                    $leftMin = $left;
                    $rightMin = $right;
                }
                $lastDeliveredId = "$leftMin-$rightMin";
                $result = $client->xRange($queueName, $queueFirstId, $lastDeliveredId, 100);
                foreach ($result as $id => $value) {
                    if($id !== $lastDeliveredId) {
                        $client->xDel($queueName, [$id]);
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
     * 消息发布
     *  1. 多队列模式不能保证事务
     *
     * @param string $body
     * @param array $headers = [
     *  @see Headers
     * ]
     * @param string|null $queueName
     * @return array 返回成功的消息ID组
     */
    public function publishGetIds(string $body, array $headers = [], null|string $queueName = null): array
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
        $ids = [];
        try {
            foreach ($queues as $queue) {
                if ($queueSize > 0) {
                    $queueLen = $client->xLen($queue);
                    if($queueLen >= $queueSize){
                        throw new WebmanRqueueException('Queue size exceeded.');
                    }
                }
                if ($id = $client->xAdd($queue, (string)$header->_id, [
                    '_header' => $header->toString(),
                    '_body'   => $body,
                ])) {
                    $ids[] = $id;
                }
            }
            return $ids;
        } catch (RedisException $exception) {
            $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * 消息发布
     *   1. 多队列模式不能保证事务
     *
     * @param string $body
     * @param array $headers = [
     *  @param string|null $queueName
     * @return int|false 0/false 代表全部失败
     *@see Headers
     * ]
     */
    public function publish(string $body, array $headers = [], null|string $queueName = null): int|false
    {
        return count($this->publishGetIds($body, $headers, $queueName));
    }

    /**
     * @param string $body
     * @param array $headers = [
     *  @see Headers
     * ]
     * @return int|false
     */
    public function requeue(string $body, array $headers = []): int|false
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
        $count = 0;
        $queues = $this->getBuilderConfig()->getQueues();
        foreach ($queues as $queue) {
            try {
                if (!$client->xAdd($queue, (string)$header->_id, $data = [
                    '_header' => $header->toString(),
                    '_body'   => $body,
                ])) {
                    return false;
                }
                $count ++;
            } catch (RedisException $exception) {
                $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
                if (isset($data)) {
                    $this->tempInsert('requeue', $queue, $data);
                }
            }
        }
        return $count;
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
                $datas = $client->xAutoClaim(
                    $queueName, $groupName, $consumerName,
                    $pendingTimeout * 1000,
                    '0-0', -1
                );
                if (is_array($datas)) {
                    foreach ($datas as $k => $v) {
                        if (!$v or $v === '0-0') {
                            unset($datas[$k]);
                        }
                    }
                    if ($datas) {
                        if ($client->xAck($queueName, $groupName, $datas)) {
                            // pending超时的消息自动ack，并存入本地缓存
                            try {
                                foreach ($datas as $message) {
                                    $header = new Headers($message['_header']);
                                    $body = $message['_body'];
                                    $this->tempInsert('pending', $queueName, $message);
                                    $this->requeue($body, $header->toArray());
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
                if (is_array($res)) {
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
                                    $this->requeue($body, $header->toArray());
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
                                    $this->requeue($body, $header->toArray());
                                }
                            }
                        }
                        // del
                        if($del) { $client->xDel($queueName, $ids); }
                    }
                }
            }
        }catch (RedisException $exception) {
            $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}