<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders;

use Illuminate\Redis\Connections\Connection;
use support\Redis;
use Workbunny\WebmanRqueue\BuilderConfig;
use Workerman\Worker;

abstract class AbstractBuilder
{
    public static bool $debug = false;
    /**
     * @var AbstractBuilder[]
     */
    private static array $_builders = [];

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
        $this->setConnection(Redis::connection(config('plugin.workbunny.webman-rabbitmq.app.connection', 'default')));
        $this->setBuilderConfig(new BuilderConfig());
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