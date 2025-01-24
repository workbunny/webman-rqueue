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

abstract class CoQueueBuilder extends QueueBuilder
{

    /** @inheritDoc */
    public function onWorkerStart(Worker $worker): void
    {
        // 初始化temp库
        $this->tempInit();
        if ($this->getConnection()) {
            // requeue timer
            $this->tempRequeueInit();
            // check pending
            if (($pendingTimeout = $this->configs['pending_timeout'] ?? 0) > 0) {
                $this->setPendingTimer(Timer::add($pendingTimeout / 1000, function () use ($worker, $pendingTimeout) {
                    // 超时消息自动ack并requeue，消息自动移除
                    $this->claim($worker, $pendingTimeout);
                }));
            }

            while (1) {
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
                } finally {
                    // 协程随机出让 5 - 10 ms
                    $coInterval = $this->configs['co_interval'] ?? [];
                    Timer::sleep(($coInterval ? rand($coInterval[0], $coInterval[1]) : rand(5, 10)) / 1000);
                }
            }
        }
    }

    /** @inheritDoc */
    public static function classContent(string $namespace, string $className, bool $isDelay): string
    {
        $isDelay = $isDelay ? 'true' : 'false';
        $name = self::getName("$namespace\\$className");
        return <<<doc
<?php declare(strict_types=1);

namespace $namespace;

use Workbunny\WebmanRqueue\Headers;
use Workbunny\WebmanRqueue\Builders\CoQueueBuilder;
use Illuminate\Redis\Connections\Connection;

class $className extends CoQueueBuilder
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
        'pending_timeout' => 0,
        // 协程随机出让 5 - 10 ms
        'co_interval'     => [5, 10]             
    ];
    
    /** @var float|null 消费长轮询时长/消费间隔 100ms */
    protected ?float \$timerInterval = 100.0;
    
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
}