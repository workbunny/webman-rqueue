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
     *  'queue'          => 'example',
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
        $name = str_replace('\\', '.', get_called_class());
        $this->getBuilderConfig()->setGroup($this->config['group'] ?? $name);
        $this->getBuilderConfig()->setQueue($this->config['queue'] ?? $name);
        $this->getBuilderConfig()->setQueueSize($this->config['queue_size'] ?? 0);
        $this->getBuilderConfig()->setPrefetchCount($this->config['prefetch_count'] ?? 0);
        $this->getBuilderConfig()->setDelayed($this->config['delayed'] ?? false);
        $this->getBuilderConfig()->setCallback([$this, 'handler']);
    }

    /** @inheritDoc */
    public function onWorkerStart(Worker $worker): void
    {
        if($this->getConnection()){
            // check pending

            // del timer
            self::$_delTimer = Timer::add($this->timerInterval / 1000, function() use ($worker) {
                $this->del();
            });
            // consume timer
            self::setMainTimer(Timer::add($this->timerInterval / 1000, function () use ($worker) {
                $this->consume($worker);
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
        return <<<doc
<?php declare(strict_types=1);

namespace $namespace;

use Workbunny\WebmanRqueue\Builders\QueueBuilder;
use Illuminate\Redis\Connections\Connection;

class $className extends QueueBuilder
{
    
    /** @see QueueBuilder::\$config */
    protected array \$configs = [
        'queue'          => '',          // TODO 队列名称 ，默认由类名自动生成
        'group'          => '',          // TODO 分组名称，默认由类名自动生成
        'delayed'        => $isDelay,    // TODO 是否延迟
        'prefetch_count' => 0,           // TODO QOS 数量
        'queue_size'     => 0,           // TODO Queue size
    ];
    
    /** @var float|null 消费间隔 1ms */
    protected ?float \$timerInterval = 1.0;
    
    /** @var string redis配置 */
    protected string \$connection = 'default';
    
    /**
     * 【请勿移除该方法】
     * @param string \$id
     * @param array \$value = [
     *     '_header' => [],
     *     '_body'  => '',
     * ]
     * @param Connection \$connection
     * @return bool
     */
    public function handler(string \$id, array \$value, Connection \$connection): bool 
    {
        // TODO 请重写消费逻辑
        echo "请重写 $className::handler\\n";
        return true;
    }
}
doc;
    }
}