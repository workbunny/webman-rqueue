<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders;

use Illuminate\Redis\Connections\Connection;
use RedisException;
use Workbunny\WebmanRqueue\Builders\Traits\MessageQueueMethod;
use Workerman\Timer;
use Workerman\Worker;

class GroupBuilder extends AbstractBuilder
{
    use MessageQueueMethod;

    /**
     * 配置
     *
     * @var array = [
     *  'queues'         => ['example'],
     *  'group'          => 'example',
     *  'delayed'        => false,
     *  'prefetch_count' => 1,
     *  'queue_size'     => 0,
     * ]
     */
    protected array $config = [];

    private static ?int $_delTimer = null;

    public function __construct()
    {
        parent::__construct();
        $name = self::getName();
        $this->getBuilderConfig()->setGroup($this->config['group'] ?? $name);
        $this->getBuilderConfig()->setQueues($this->config['queues'] ?? [$name]);
        $this->getBuilderConfig()->setQueueSize($this->config['queue_size'] ?? 0);
        $this->getBuilderConfig()->setPrefetchCount($this->config['prefetch_count'] ?? 0);
        $this->getBuilderConfig()->setDelayed($this->config['delayed'] ?? false);
        $this->getBuilderConfig()->setCallback([$this, 'handler']);
    }

    /** @inheritDoc */
    public function onWorkerStart(Worker $worker): void
    {
        if($this->getConnection()){
            // del timer
            self::$_delTimer = Timer::add($this->timerInterval, function() use ($worker) {
                // auto del
                $this->del();
            });
            // consume timer
            self::setMainTimer(Timer::add($this->timerInterval / 1000, function () use ($worker) {
                // consume
                $this->consume($worker);
                // todo check pending
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
        if(self::getMainTimer()) {
            Timer::del(self::getMainTimer());
        }
        if(self::$_delTimer) {
            Timer::del(self::$_delTimer);
        }
    }

    /** @inheritDoc */
    public function onWorkerReload(Worker $worker): void
    {}

    /**
     * @param array $ids
     * @param string $id
     * @return array
     */
    public function idsAdd(array &$ids, string $id): array
    {
        if($this->getHeader()->_delete) {
            $ids[] = $id;
        }
        return [$id];
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
use Workbunny\WebmanRqueue\Builders\GroupBuilder;
use Illuminate\Redis\Connections\Connection;

class $className extends GroupBuilder
{
    
    /** @see QueueBuilder::\$config */
    protected array \$configs = [
        // 默认由类名自动生成
        'queues'         => [
            '$name'
        ],
        // 默认由类名自动生成        
        'group'          => '$name', 
        // 是否延迟         
        'delayed'        => $isDelay,
        // QOS    
        'prefetch_count' => 0,
        // Queue size
        'queue_size'     => 0,           
    ];
    
    /** @var float|null 消费间隔 1ms */
    protected ?float \$timerInterval = 1.0;
    
    /** @var string redis配置 */
    protected string \$connection = 'default';
    
    /**
     * 【请勿移除该方法】
     * @param string \$id
     * @param array \$value = [
     *     '_header' => json_string,
     *     '_body'  => string,
     * ]
     * @param Connection \$connection
     * @return bool
     */
    public function handler(string \$id, array \$value, Connection \$connection): bool 
    {
        \$header = new Headers(\$value['_header']);
        \$body   = \$value['_body']
        // TODO 请重写消费逻辑
        echo "请重写 $className::handler\\n";
        return true;
    }
}
doc;
    }
}