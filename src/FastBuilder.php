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
     * @var string|null
     */
    private ?string $_group = null;

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
            $queue = $this->getMessage()->getQueue();
            $group = $this->getMessage()->getGroup() . $worker->id;

            if($this->_group === null){
                $client->xGroup('CREATE', $queue, $group, '0', true);
                $this->_group = $group;
            }

            if($res = $this->connection()->client()->xReadGroup(
                $group,
                'consumer',
                [$this->getMessage()->getQueue() => '>'],
                $this->getMessage()->getPrefetchCount(),
                (int)($interval * 1000)
            )){

                foreach ($res as $queue => $message){
                    foreach ($message as $id => $value){
                        $body = $value['body'] ?? '';
                        $delay = $value['delay'] ?? 0;
                        $timestamp = $value['timestamp'] ?? 0;

                        if($delay > 0 and (($delay / 1000 + $timestamp) - microtime(true)) > 0){
                            $client->xAdd($queue,'*', $value);
                            $client->xAck($queue, $group, [$id]);
                            continue;
                        }
                        try {
                            if(($this->getMessage()->getCallback())($body, $this->connection())){
                                $client->xAck($queue, $group, [$id]);
                                continue;
                            }
                        }catch (\Throwable $throwable){}
                    }
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
            $queue = $this->getMessage()->getQueue();
            $group = $this->getMessage()->getGroup() . $worker->id;
            $this->_connection->client()->xGroup('DESTROY', $queue, $group);
            $this->_group = null;

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