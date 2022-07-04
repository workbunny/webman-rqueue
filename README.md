<p align="center"><img width="260px" src="https://chaz6chez.cn/images/workbunny-logo.png" alt="workbunny"></p>

**<p align="center">workbunny/webman-rqueue</p>**

**<p align="center">🐇 A lightweight queue based on Redis Stream for webman plugin. 🐇</p>**

# A lightweight queue based on Redis Stream for webman plugin


[![Latest Stable Version](http://poser.pugx.org/workbunny/webman-rqueue/v)](https://packagist.org/packages/workbunny/webman-rqueue) [![Total Downloads](http://poser.pugx.org/workbunny/webman-rqueue/downloads)](https://packagist.org/packages/workbunny/webman-rqueue) [![Latest Unstable Version](http://poser.pugx.org/workbunny/webman-rqueue/v/unstable)](https://packagist.org/packages/workbunny/webman-rqueue) [![License](http://poser.pugx.org/workbunny/webman-rqueue/license)](https://packagist.org/packages/workbunny/webman-rqueue) [![PHP Version Require](http://poser.pugx.org/workbunny/webman-rqueue/require/php)](https://packagist.org/packages/workbunny/webman-rqueue)

## 常见问题

1. 什么时候使用消息队列？

	**当你需要对系统进行解耦、削峰、异步的时候；如发送短信验证码、秒杀活动、资产的异步分账清算等。**

2. RabbitMQ和Redis的区别？

	**Redis中的Stream的特性同样适用于消息队列，并且也包含了比较完善的ACK机制，但在一些点上与RabbitMQ存在不同：**
	- **Redis Stream没有完善的后台管理；RabbitMQ拥有较为完善的后台管理及Api；**
	- **Redis的持久化策略取舍：默认的RDB策略极端情况下存在丢失数据，AOF策略则需要牺牲一些性能；RabbitMQ持久化方案更多，可对消息持久化也可对队列持久化；**
	- **RabbitMQ拥有更多的插件可以提供更完善的协议支持及功能支持；**

3. 什么时候使用Redis？什么时候使用RabbitMQ？

	**当你的队列使用比较单一或者比较轻量的时候，请选用 Redis Stream；当你需要一个比较完整的消息队列体系，包括需要利用交换机来绑定不同队列做一些比较复杂的消息任务的时候，请选择RabbitMQ；**

	**当然，如果你的队列使用也比较单一，但你需要用到一些管理后台相关系统化的功能的时候，又不想花费太多时间去开发的时候，也可以使用RabbitMQ；因为RabbitMQ提供了一整套后台管理的体系及 HTTP API 供开发者兼容到自己的管理后台中，不需要再消耗多余的时间去开发功能；**

	注：这里的 **轻量** 指的是 **无须将应用中的队列服务独立化，该队列服务是该应用独享的**

## 简介

基于Redis Stream的轻量级队列；

简单易用高效，可以轻易的实现master/worker的队列模式（一个队列多个消费者）；

支持延迟消息；

## 安装

```
composer require workbunny/webman-rqueue
```

## 使用

### 创建Builder

**Builder** 可以理解为类似 **ORM** 的 **Model**，创建一个 **Builder** 就对应了一个队列；使用该 **Builder** 对象进行 **publish()** 时，会向该队列投放消息；创建多少个 **Builder** 就相当于创建了多少条队列；

- **创建一个消费者进程数量为1的普通队列：（在项目根目录执行）**

```shell
# 在 process/workbunny/rqueue 目录下创建 TestBuilder.php
./webman workbunny:rqueue-builder test 1
```

- **创建一个消费者进程数量为1的延迟队列：（在项目根目录执行）**
```shell
# 在 process/workbunny/rqueue 目录下创建 TestBuilderDelayed.php
./webman workbunny:rqueue-builder test 1 -d
# 或
./webman workbunny:rqueue-builder test 1 --delayed
```

- **命令支持二级菜单**
```shell
# 在 process/workbunny/rqueue/project 目录下创建 TestBuilder.php
./webman workbunny:rqueue-builder project/test 1

# 延迟同理
```

#### 说明：

- **命令结构：**
```shell
workbunny:rqueue-builder [-d|--delayed] [--] <name> <count>

# 【必填】 name：Builder名称
# 【必填】count：启动的消费者进程数量
# 【选填】-d/--delayed：是否是延迟队列
```

- 在项目根目录下命令会在 **process/workbunny/rqueue** 路径下创建一个Builder，并且将该Builder自动加入 **config/plugin/workbunny/webman-rqueue/process.php** 配置中作为自定义进程启动；**（如不需要自动加载消费者进程，请自行注释该配置）**；

- 消费是异步的，不会阻塞当前进程，不会影响 **webman/workerman** 的 **status**；

- **Builder文件结构入下，可自行调整类属性：**
```php
<?php
declare(strict_types=1);

namespace process\workbunny\rqueue;

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
    // 消费回调
    public function handler(string $msgid, array $msgvalue, Connection $connection): bool
    {
    	var_dump($msgid); # 消息id
        var_dump($msgvalue); # 消息体
        return true; // ack
        # false // nack
        # throw // nack
    }
}
```

### 移除Builder

- **移除名为 test 的普通队列：（在项目根目录执行）**

```shell
./webman workbunny:rqueue-remove test
```

- **移除名为 test 的延迟队列：（在项目根目录执行）**
```shell
./webman workbunny:rqueue-remove test -d
# 或
./webman workbunny:rqueue-remove test --delayed
```

### 查看Builder

```shell
./webman workbunny:rqueue-list
```

**注：当 Builder 未启动时，handler 与 count 显示为 --**

```shell
+----------+--------------------------------------------------------------------+-------------------------------------------------+-------+
| name     | file                                                               | handler                                         | count |
+----------+--------------------------------------------------------------------+-------------------------------------------------+-------+
| test     | /var/www/your-project/process/workbunny/rqueue/TestBuilder.php     | process\workbunny\rqueue\TestBuilder            | 1     |
+----------+--------------------------------------------------------------------+-------------------------------------------------+-------+
```

### 生产

- 每个 Builder 各包含一个连接，使用多个 Builder 会创建多个连接

- 生产消息默认不关闭当前连接

#### 1. 同步发布消息

**该方法会阻塞等待至消息生产成功，返回bool**

- 发布普通消息

**注：向普通队列发布延迟消息会抛出一个 WebmanRqueueException 异常**

```php
use function Workbunny\WebmanRabbitMQ\sync_publish;
use process\workbunny\rqueue\TestBuilder;

sync_publish(TestBuilder::instance(), 'abc'); # return bool
```

- 发布延迟消息

**注：向延迟队列发布普通消息会抛出一个 WebmanRqueueException 异常**

```php
use function Workbunny\WebmanRabbitMQ\sync_publish;
use process\workbunny\rqueue\TestBuilder;

# 延迟10秒
sync_publish(TestBuilder::instance(), 'abc', 10000); # return bool
```

## 说明

- **小范围生产验证中，欢迎 [issue](https://github.com/workbunny/webman-rqueue/issues) 和 PR**；

- **Redis Stream** 本身没有 **delayed** 或 **non-delayed** 之分，组件代码将它们区分的原因是不希望 **delayed** 被滥用；开发者应该明确哪些消息是延迟的、哪些是立即的，并且明确体现，也方便维护，因为延迟消息过多会导致消息堆积，从而占用Redis过多的资源；

- **Redis Stream** 的持久化依赖 **Redis** 本身的持久化策略，在一定情况下 **Redis Stream** 也并非是可靠型的消息队列;关于持久化相关内容，请仔细阅读 **[Redis中文文档](http://www.redis.cn/topics/persistence.html)**；

- 继承实现 **AbstractMessage** 可以自定义Message；

- **Builder** 可通过 **Builder->setMessage()** 可设置自定义配置；
