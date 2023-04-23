<?php declare(strict_types=1);

namespace Workbunny\Tests\Builders;

use Illuminate\Redis\Connections\Connection;
use Workbunny\WebmanRqueue\Builders\QueueBuilder;

class TestQueueBuilderDelayed extends QueueBuilder
{
    
    /** @see QueueBuilder::$config */
    protected array $configs = [
        'queue'          => '',          // TODO 队列名称 ，默认由类名自动生成
        'group'          => '',          // TODO 分组名称，默认由类名自动生成
        'delayed'        => true,        // TODO 是否延迟
        'prefetch_count' => 0,           // TODO QOS 数量
        'queue_size'     => 0,           // TODO Queue size
    ];
    
    /** @var float|null 消费间隔 1ms */
    protected ?float $timerInterval = 1.0;
    
    /** @var string redis配置 */
    protected string $connection = 'default';
    
    /**
     * 【请勿移除该方法】
     * @param string $id
     * @param array $value = [
     *     '_header' => [],
     *     '_body'  => '',
     * ]
     * @param Connection $connection
     * @return bool
     */
    public function handler(string $id, array $value, Connection $connection): bool 
    {
        // TODO 请重写消费逻辑
        echo "请重写 TestQueueBuilderDelayed::handler\n";
        return true;
    }
}