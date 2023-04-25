<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders;

use Illuminate\Redis\Connections\Connection;
use support\Redis;
use Workbunny\WebmanRqueue\BuilderConfig;
use Workbunny\WebmanRqueue\Builders\Traits\MessageQueueMethod;
use Workbunny\WebmanRqueue\Headers;
use Workerman\Worker;

abstract class AbstractBuilder
{
    use MessageQueueMethod;

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
    private static ?int $_mainTimer = null;

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

    public function __construct()
    {
        $this->setBuilderConfig(new BuilderConfig());
        $this->setHeader(new Headers());
        $this->setConnection(Redis::connection($this->connection));
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
    public static function getMainTimer(): ?int
    {
        return self::$_mainTimer;
    }

    /**
     * @param int|null $mainTimer
     */
    public static function setMainTimer(?int $mainTimer): void
    {
        self::$_mainTimer = $mainTimer;
    }

    public static function getName(string|null $class): string
    {
        return str_replace('\\', ':', $class ?? get_called_class());
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