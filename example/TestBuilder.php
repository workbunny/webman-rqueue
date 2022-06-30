<?php
declare(strict_types=1);

namespace Examples;

use Illuminate\Redis\Connections\Connection;
use Workbunny\WebmanRqueue\FastBuilder;

class TestBuilder extends FastBuilder
{
    // 默认的redis连接配置
    protected string $connection = 'default';
    // 消费组QOS
    protected int $prefetch_count = 1;
    // 队列最大数量
    protected int $queue_size = 4096;
    // 是否延迟队列
    protected bool $delayed = false;

    public function handler(string $body, Connection $connection): bool
    {
        return true;
    }
}