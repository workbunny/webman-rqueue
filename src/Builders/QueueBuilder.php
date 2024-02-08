<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders;

use Illuminate\Redis\Connections\Connection;
use Psr\Log\LoggerInterface;
use RedisException;
use support\Log;
use Workbunny\WebmanRqueue\Builders\Traits\MessageQueueMethod;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;
use Workerman\Timer;
use Workerman\Worker;

abstract class QueueBuilder extends AbstractBuilder
{

    /**
     * 配置
     *
     * @var array = [
     *  'queues'          => ['example'],
     *  'group'           => 'example',
     *  'delayed'         => false,
     *  'prefetch_count'  => 1,
     *  'queue_size'      => 0,
     *  'pending_timeout' => 0
     * ]
     */
    protected array $configs = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct($logger);
        $name = self::getName();
        $this->getBuilderConfig()->setGroup($this->configs['group'] ?? $name);
        $this->getBuilderConfig()->setQueues($this->configs['queues'] ?? [$name]);
        $this->getBuilderConfig()->setQueueSize($this->configs['queue_size'] ?? 0);
        $this->getBuilderConfig()->setPrefetchCount($this->configs['prefetch_count'] ?? 0);
        $this->getBuilderConfig()->setDelayed($this->configs['delayed'] ?? false);
        $this->getBuilderConfig()->setCallback([$this, 'handler']);
    }

    /** @inheritDoc */
    public function onWorkerStart(Worker $worker): void
    {
        // 初始化temp库
        $this->tempInit();
        if($this->getConnection()){
            // requeue timer
            $this->tempRequeueInit();
            // check pending
            if (($pendingTimeout = $this->configs['pending_timeout'] ?? 0) > 0) {
                $this->setPendingTimer(Timer::add($pendingTimeout / 1000, function () use ($worker, $pendingTimeout) {
                    // 超时消息自动ack并requeue，消息自动移除
                    $this->claim($worker, $pendingTimeout);
                }));
            }
            // main timer
            $this->setMainTimer(Timer::add($this->timerInterval / 1000, function () use($worker) {
                try {
                    // consume
                    $this->consume($worker);
                } catch (WebmanRqueueException $exception) {
                    Log::channel('plugin.workbunny.webman-rqueue.warning')?->warning('Consume exception. ', [
                        'message' => $exception->getMessage(), 'code' => $exception->getCode(),
                        'file'  => $exception->getFile() . ':' . $exception->getLine(),
                        'trace' => $exception->getTrace()
                    ]);
                    // 兼容旧版
                    $this->getLogger()?->warning('Consume exception. ', [
                        'message' => $exception->getMessage(), 'code' => $exception->getCode()
                    ]);
                }
            }));
        }
    }

    /** @inheritDoc */
    public function onWorkerStop(Worker $worker): void
    {
        if($this->getConnection()) {
            try {
                $this->getConnection()->client()->close();
            }catch (RedisException $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
        if($this->getMainTimer()) {
            Timer::del($this->getMainTimer());
        }
    }

    /** @inheritDoc */
    public function onWorkerReload(Worker $worker): void
    {}

    /** @inheritDoc */
    public static function classContent(string $namespace, string $className, bool $isDelay): string
    {
        $isDelay = $isDelay ? 'true' : 'false';
        $name = self::getName("$namespace\\$className");
        return <<<doc
<?php declare(strict_types=1);

namespace $namespace;

use Workbunny\WebmanRqueue\Headers;
use Workbunny\WebmanRqueue\Builders\QueueBuilder;
use Illuminate\Redis\Connections\Connection;

class $className extends QueueBuilder
{
    
    /** @see QueueBuilder::\$configs */
    protected array \$configs = [
        // 默认由类名自动生成
        'queues'          => [
            '$name'
        ],
        // 默认由类名自动生成        
        'group'           => '$name',
        // 是否延迟         
        'delayed'         => $isDelay,
        // QOS    
        'prefetch_count'  => 0,
        // Queue size
        'queue_size'      => 0,
        // 消息pending超时，毫秒
        'pending_timeout' => 0             
    ];
    
    /** @var float|null 消费间隔 1ms */
    protected ?float \$timerInterval = 1.0;
    
    /** @var string redis配置 */
    protected string \$connection = 'default';
    
    /** @inheritDoc */
    public function handler(string \$id, array \$value, Connection \$connection): bool 
    {
        \$header = new Headers(\$value['_header']);
        \$body   = \$value['_body'];
        // TODO 请重写消费逻辑
        echo "请重写 $className::handler\\n";
        return true;
    }
}
doc;
    }

    /**
     * 消费函数
     * @param string $id
     * @param array $value = [
     *     '_header' => json_string,
     *     '_body'  => string,
     * ]
     * @param Connection $connection
     * @return bool
     */
    abstract public function handler(string $id, array $value, Connection $connection): bool;
}