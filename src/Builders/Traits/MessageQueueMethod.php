<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use RedisException;
use support\Log;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;
use Workbunny\WebmanRqueue\Headers;
use Workerman\Worker;
use function Workbunny\WebmanRqueue\config;

trait MessageQueueMethod
{
    use MessageTempMethod;

    protected array $claimStartTags = [];

    protected bool $_init = false;

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
     * @param bool $retry
     * @return bool
     */
    public function ack(string $queueName, string $groupName, array $id, bool $retry = false): bool
    {
        try {
            $this->getConnection()->client()->xAck($queueName, $groupName, $id);
            return true;
        } catch (RedisException) {
            Log::channel('plugin.workbunny.webman-rqueue.warning')?->warning("Ack failed [$queueName->$groupName]. ", [
                'message' => $exception->getMessage(), 'code' => $exception->getCode(),
                'file'  => $exception->getFile() . ':' . $exception->getLine(),
                'trace' => $exception->getTrace(), 'ids' => $id
            ]);
            // 兼容旧版
            $this->getLogger()?->warning("Ack failed. ", [
                'queue' => $queueName, 'group' => $groupName, 'id' => $id
            ]);
        }
        if ($retry) {
            // 阻塞当前进程，重试
            $sleep = 250 * 1000;
            $index = 2;
            $max = 5 * 60 * 1000 * 1000;
            while (1) {
                Log::channel('plugin.workbunny.webman-rqueue.notice')
                    ->notice($message = __CLASS__ . "| Consumer blocking-retry! [usleep: $sleep] ", [
                        'queue' => $queueName, 'group' => $groupName, 'id' => $id, 'sleep' => $sleep
                    ]);
                // 日志
                $this->getLogger()?->notice($message, [
                    'queue' => $queueName, 'group' => $groupName, 'id' => $id, 'sleep' => $sleep
                ]);
                // 输出
                echo $message . PHP_EOL;
                // 每次阻塞指数倍数上升，最大max
                usleep($sleep);
                $sleep = min(($sleep * $index), $max);
                if ($this->ack($queueName, $groupName, $id)) {
                    break;
                }
            }
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
     * @param bool $temp
     * @return array 返回成功的消息ID组
     */
    public function publishGetIds(string $body, array $headers = [], null|string $queueName = null, bool $temp = false): array
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
                if ($id = $client->xAdd($queue, (string)$header->_id, $data = [
                    '_header' => $header->toString(),
                    '_body'   => $body,
                ])) {
                    $ids[$queue] = $id;
                } else {
                    if ($temp) {
                        $this->tempInsert('requeue', $queue, $data);
                        $ids["<temp>$queue</temp>"] = $id;
                    }
                }
            }
            return $ids;
        } catch (RedisException $exception) {
            Log::channel('plugin.workbunny.webman-rqueue.debug')?->debug($exception->getMessage(), $exception->getTrace());
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
     *  @see Headers
     * ]
     * @param string|null $queueName
     * @param bool $temp
     * @return int|false 0/false 代表全部失败
     */
    public function publish(string $body, array $headers = [], null|string $queueName = null , bool $temp = false): int|false
    {
        return count($this->publishGetIds($body, $headers, $queueName, $temp));
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
                Log::channel('plugin.workbunny.webman-rqueue.debug')?->debug($exception->getMessage(), $exception->getTrace());
                $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
                if (isset($data)) {
                    $this->tempInsert('requeue', $queue, $data);
                    echo 'temp requeue insert' . PHP_EOL;
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
            if (!method_exists($client, 'xAutoClaim')) {
                Log::channel('plugin.workbunny.webman-rqueue.warning')->warning(
                    'Method xAutoClaim requires redis-server >= 6.2.0. '
                );
                return;
            }
            $builderConfig = $this->getBuilderConfig();
            $queues = $builderConfig->getQueues();
            $groupName = $builderConfig->getGroup();
            $consumerName = "$groupName-$worker->id";
            foreach ($queues as $queueName) {
                $datas = $client->xAutoClaim(
                    $queueName, $groupName, $consumerName,
                    $pendingTimeout * 1000,
                    $this->claimStartTags[$queueName][$groupName][$consumerName] ?? '0-0', -1
                );
                if ($datas) {
                    $this->claimStartTags[$queueName][$groupName][$consumerName] = $datas[0] ?? '0-0';
                    if ($datas = $datas[2] ?? []) {
                        if ($client->xAck($queueName, $groupName, $datas)) {
                            // pending超时的消息自动ack，并存入本地缓存
                            try {
                                foreach ($datas as $message) {
                                    $header = new Headers($message['_header']);
                                    $body = $message['_body'];
                                    $this->tempInsert('pending', $queueName, $message);
                                    echo 'temp pending insert' . PHP_EOL;
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
            Log::channel('plugin.workbunny.webman-rqueue.debug')?->debug($exception->getMessage(), $exception->getTrace());
            $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param Worker $worker
     * @param bool $del
     * @return bool true:有消费 false:无消费
     * @throws WebmanRqueueException
     */
    public function consume(Worker $worker, bool $del = true): bool
    {
        try {
            $client = $this->getConnection()->client();
            $builderConfig = $this->getBuilderConfig();
            $queues = $builderConfig->getQueues();
            $groupName = $builderConfig->getGroup();
            $consumerName = "$groupName-$worker->id";
            // create group
            $queueStreams = [];
            if (!$this->_init) {
                foreach ($queues as $queueName) {
                    $client->xGroup('CREATE', $queueName, $groupName,'0', true);
                    $queueStreams[$queueName] = '>';
                }
                $this->_init = true;
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
                            // republish
                            $header->_id = '*';
                            if ($this->requeue($body, $header->toArray())) {
                                // blocking-retry ack
                                $this->ack($queueName, $groupName, $this->idsAdd($ids, $id), true);
//                                if (!$this->ack($queueName, $groupName, $this->idsAdd($ids, $id))) {
//                                    $this->idsDel($ids, $id);
//                                }
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
                            // republish
                            $header->_count = $header->_count + 1;
                            $header->_error = "{$throwable->getMessage()} [{$throwable->getFile()}:{$throwable->getLine()}]";
                            // republish都将刷新使用redis stream自身的id，自定义id无效
                            $header->_id    = '*';
                            // 如果错误超过error max count，则存入本地error表
                            if (
                                ($errorMaxCount = config('plugin.workbunny.webman-rqueue.app.error_max_count', 0)) > 0 and
                                $header->_count >= $errorMaxCount
                            ) {
                                // 存入
                                if ($this->tempInsert('error', $queueName, [
                                    '_header' => $header->toArray(),
                                    '_body'   => $body
                                ])) {
                                    // blocking-retry ack
                                    $this->ack($queueName, $groupName, $this->idsAdd($ids, $id), true);
                                }
                            } else {
                                if ($this->requeue($body, $header->toArray())) {
                                    // blocking-retry ack
                                    $this->ack($queueName, $groupName, $this->idsAdd($ids, $id), true);
//                                if (!$this->ack($queueName, $groupName, $this->idsAdd($ids, $id))) {
//                                    $this->idsDel($ids, $id);
//                                }
                                }
                            }
                        }
                    }
                    // del
                    if($del) { $client->xDel($queueName, $ids); }
                }
                return true;
            }
            return false;
        } catch (RedisException $exception) {
            Log::channel('plugin.workbunny.webman-rqueue.debug')?->debug($exception->getMessage(), $exception->getTrace());
            $this->getLogger()?->debug($exception->getMessage(), $exception->getTrace());
            $this->_init = false;
            throw new WebmanRqueueException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}