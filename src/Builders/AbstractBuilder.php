<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders;

use Illuminate\Redis\Connections\Connection;
use Psr\Log\LoggerInterface;
use support\Redis;
use Workbunny\WebmanRqueue\BuilderConfig;
use Workbunny\WebmanRqueue\Builders\Traits\AdaptiveTimerMethod;
use Workbunny\WebmanRqueue\Builders\Traits\MessageQueueMethod;
use Workbunny\WebmanRqueue\Builders\Traits\MessageTempMethod;
use Workbunny\WebmanRqueue\Commands\AbstractCommand;
use Workbunny\WebmanRqueue\Headers;
use Workerman\Worker;

abstract class AbstractBuilder
{
    use MessageQueueMethod;
    use MessageTempMethod;

    public static bool $debug = false;

    /** @var string redis配置 */
    protected string $connection = 'default';

    /** @var float|null 消费间隔 1ms */
    protected ?float $timerInterval = 1;

    /**
     * @var AbstractBuilder[]
     */
    private static array $_builders = [];

    /**
     * @var int|null
     */
    private ?int $_mainTimer = null;

    /**
     * @var int|null
     */
    private ?int $_pendingTimer = null;

    /**
     * @var Headers|null
     */
    private ?Headers $_header = null;

    /**
     * @var BuilderConfig
     */
    private BuilderConfig $_builderConfig;

    /**
     * @var Connection
     */
    private Connection $_connection;

    /**
     * @deprecated
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $_logger = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->setBuilderConfig(new BuilderConfig());
        $this->setHeader(new Headers());
        $this->setConnection(Redis::connection($this->connection));
        $this->setLogger($logger);
    }

    /**
     * @return AbstractBuilder
     */
    public static function instance(): AbstractBuilder
    {
        if(!isset(self::$_builders[$class = get_called_class()])){
            self::$_builders[$class] = new $class();
        }
        return self::$_builders[$class];
    }

    /**
     * @param string $class
     * @return void
     */
    public static function destroy(string $class): void
    {
        unset(self::$_builders[$class]);
    }

    /**
     * @return BuilderConfig
     */
    public function getBuilderConfig(): BuilderConfig
    {
        return $this->_builderConfig;
    }

    /**
     * @param BuilderConfig $builderConfig
     */
    public function setBuilderConfig(BuilderConfig $builderConfig): void
    {
        $this->_builderConfig = $builderConfig;
    }

    /**
     * @return int|null
     */
    public function getMainTimer(): ?int
    {
        return $this->_mainTimer;
    }

    /**
     * @param int|null $mainTimer
     */
    public function setMainTimer(?int $mainTimer): void
    {
        $this->_mainTimer = $mainTimer;
    }

    /**
     * @return int|null
     */
    public function getPendingTimer(): ?int
    {
        return $this->_pendingTimer;
    }

    /**
     * @param int|null $pendingTimer
     */
    public function setPendingTimer(?int $pendingTimer): void
    {
        $this->_pendingTimer = $pendingTimer;
    }

    /**
     * 设置定时器间隔 ms
     * @param float $ms
     * @return void
     */
    public function setTimerInterval(float $ms): void
    {
        $this->timerInterval = $ms;
    }

    /**
     * 获取定时器间隔 ms
     * @return float|null
     */
    public function getTimerInterval(): null|float
    {
        return $this->timerInterval;
    }

    /**
     * @deprecated
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->_logger;
    }

    /**
     * @deprecated
     * @param LoggerInterface|null $logger
     */
    public function setLogger(null|LoggerInterface $logger): void
    {
        $this->_logger = $logger;
    }

    /**
     * @param string|null $class
     * @param bool $short
     * @return string
     */
    public static function getName(string|null $class = null, bool $short = true): string
    {
        $class = $class ?? get_called_class();
        return str_replace('\\', ':',
            $short ? str_replace(AbstractCommand::$baseNamespace . '\\', '', $class) : $class
        );
    }

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

    /**
     * @param array $ids
     * @param string $id
     * @return void
     */
    public function idsDel(array &$ids, string $id): void
    {
        if($this->getHeader()->_delete) {
            if ($key = array_search($id, $ids)) {
                unset($ids[$key]);
            }
        }
    }

    /**
     * @return Headers|null
     */
    public function getHeader(): ?Headers
    {
        return $this->_header;
    }

    /**
     * @param Headers|null $header
     */
    public function setHeader(?Headers $header): void
    {
        $this->_header = $header;
    }

    /**
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->_connection;
    }

    /**
     * @param Connection $connection
     */
    public function setConnection(Connection $connection): void
    {
        $this->_connection = $connection;
    }

    /**
     * Builder 启动时
     *
     * @param Worker $worker
     * @return void
     */
    abstract public function onWorkerStart(Worker $worker): void;

    /**
     * Builder 停止时
     *
     * @param Worker $worker
     * @return void
     */
    abstract public function onWorkerStop(Worker $worker): void;

    /**
     * Builder 重加载时
     *
     * @param Worker $worker
     * @return void
     */
    abstract public function onWorkerReload(Worker $worker): void;

    /**
     * Command 获取需要创建的类文件内容
     *
     * @param string $namespace
     * @param string $className
     * @param bool $isDelay
     * @return string
     */
    abstract public static function classContent(string $namespace, string $className, bool $isDelay): string;
}