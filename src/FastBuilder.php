<?php
declare(strict_types=1);

namespace Workbunny\WebmanRqueue;

use Illuminate\Redis\Connections\Connection;
use Workbunny\WebmanRqueue\Protocols\AbstractMessage;
use Workbunny\WebmanRqueue\Protocols\BuilderInterface;
use Workerman\Timer;
use Workerman\Worker;
use support\Redis;

abstract class FastBuilder implements BuilderInterface
{
    protected string $connection = 'default';

    protected ?int $prefetch_count = null;

    protected int $queue_size = 4096;

    protected bool $delayed = false;

    /**
     * @var AbstractMessage|Message
     */
    private AbstractMessage $_message;

    /**
     * @var Connection|null
     */
    private ?Connection $_connection = null;

    /**
     * @var int|null
     */
    private ?int $_timer = null;

    /**
     * @var FastBuilder[]
     */
    protected static array $_builders = [];

    /**
     * @return FastBuilder|static
     */
    public static function instance() : FastBuilder
    {
        if(!isset(self::$_builders[$class = get_called_class()])){
            self::$_builders[$class] = new $class();
        }
        return self::$_builders[$class];
    }

    public function __construct()
    {
        $this->_connection = Redis::connection($this->connection);
        $name = str_replace('\\', '_', get_called_class());
        $message['queue_name'] = "workbunny:rqueue:queue:$name";
        $message['group_name'] = "workbunny:rqueue:group:$name";

        $message['prefetch_count'] = $this->prefetch_count;
        $message['queue_size'] = $this->queue_size;
        $message['is_delayed'] = $this->delayed;

        $this->_message = new Message($message);
        $this->_message->setCallback([$this, 'handler']);
    }

    /**
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {

        $this->_timer = Timer::add($interval = 0.001, function () use ($worker, $interval){

            $client = $this->connection()->client();
            // 创建组
            $client->xGroup(
                'CREATE',
                $this->getMessage()->getQueue(),
                $group = $this->getMessage()->getGroup(),
                '0',
                true
            );
            // 读取未确认的消息组
            if($res = $client->xReadGroup(
                $group,
                "consumer-$worker->id",
                [$this->getMessage()->getQueue() => '>'],
                $this->getMessage()->getPrefetchCount(),
                (int)($interval * 1000)
            )){
                // 队列组
                foreach ($res as $queue => $message){
                    $ids = [];
                    // 信息组
                    foreach ($message as $id => $value) {
                        if(isset($value['_header']) and isset($value['_body'])) {
                            // delay消息
                            if($this->getMessage()->isDelayed() and $delay > 0 and (($delay / 1000 + $timestamp) - microtime(true)) > 0) {
                                // 重入队尾
                                $client->xAdd($queue,'*', $value);
                                $client->xAck($queue, $group, [$id]);
                                $ids[] = $id;
                                continue;
                            }
                            try {
                                // 消费回调handler
                                if(($this->getMessage()->getCallback())($id, $value, $this->connection())){
                                    $client->xAck($queue, $group, [$id]);
                                    $ids[] = $id;
                                    continue;
                                }
                            }catch (\Throwable $throwable){
                                continue;
                            }
                        }

                        $client->xAck($queue, $group, [$id]);
                        $ids[] = $id;
                    }
                    // 删除ack的消息
                    Timer::add($interval, function() use ($client, $queue, $ids){
                        $client->xDel($queue,$ids);
                    }, [], false);
                }
            }
        });
    }

    /**
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStop(Worker $worker): void
    {
        if($this->_connection){
            $this->_connection->client()->close();
            $this->_connection = null;
        }

        if($this->_timer){
            Timer::del($this->_timer);
            $this->_timer = null;
        }
    }

    /**
     * @param Worker $worker
     * @return void
     */
    public function onWorkerReload(Worker $worker): void
    {}

    /**
     * 获取连接
     * @return Connection
     */
    public function connection() : Connection
    {
        if(!$this->_connection instanceof Connection){
            $this->_connection = Redis::connection($this->connection);
        }
        return $this->_connection;
    }

    /**
     * @return Message|null
     */
    public function getMessage(): ?Message
    {
        return $this->_message;
    }

    /**
     * @param AbstractMessage $message
     * @return void
     */
    public function setMessage(AbstractMessage $message): void
    {
        $this->_message = $message;
        $this->_message->setCallback([$this, 'handler']);
    }

}