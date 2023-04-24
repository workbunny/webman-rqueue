<p align="center"><img width="260px" src="https://chaz6chez.cn/images/workbunny-logo.png" alt="workbunny"></p>

**<p align="center">workbunny/webman-rqueue</p>**

**<p align="center">🐇 A lightweight queue based on Redis Stream for webman plugin. 🐇</p>**

# A lightweight queue based on Redis Stream for webman plugin


<div align="center">
    <a href="https://github.com/workbunny/webman-rqueue/actions">
        <img src="https://github.com/workbunny/webman-rqueue/actions/workflows/CI.yml/badge.svg" alt="Build Status">
    </a>
    <a href="https://github.com/workbunny/webman-rqueue/blob/main/composer.json">
        <img alt="PHP Version Require" src="http://poser.pugx.org/workbunny/webman-rqueue/require/php">
    </a>
    <a href="https://github.com/workbunny/webman-rqueue/blob/main/LICENSE">
        <img alt="GitHub license" src="http://poser.pugx.org/workbunny/webman-rqueue/license">
    </a>

</div>

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

- 基于Redis Stream的轻量级队列；
- Queue 模式：多个消费者竞争消费
- Group 模式：多个消费组订阅消费

支持延迟消息；

## 安装

```
composer require workbunny/webman-rqueue
```

## 使用

### QueueBuilder

- 一个QueueBuilder类对应一个消费Group和一个消费逻辑 ```QueueBuilder::handler()```
- 一个QueueBuilder可对应一个/多个Redis-Stream-Key，通过配置 ```QueueBuilder::$config['queues']```
- QueueBuilder类使用定时器进行消费，每一次消费之后会根据消息的属性 ```_header['_delete']``` 来进行消息释放

#### 命令行

- 创建
```shell
# 创建一个拥有单进程消费者的QueueBuilder
./webman workbunny:rqueue-builder test --mode=queue
# 创建一个拥有4进程消费者的QueueBuilder
./webman workbunny:rqueue-builder test 4 --mode=queue

# 创建一个拥有单进程消费者的延迟QueueBuilder
./webman workbunny:rqueue-builder test --delayed--mode=queue
# 创建一个拥有4进程消费者的延迟QueueBuilder
./webman workbunny:rqueue-builder test 4 --delayed--mode=queue


# 在 process/workbunny/rqueue 目录下创建 TestBuilder.php
./webman workbunny:rqueue-builder test --mode=queue
# 在 process/workbunny/rqueue/project 目录下创建 TestBuilder.php
./webman workbunny:rqueue-builder project/test --mode=queue
# 在 process/workbunny/rqueue/project 目录下创建 TestAllBuilder.php
./webman workbunny:rqueue-builder project/testAll --mode=queue
# 延迟同理
```


- 移除

移除包含了类文件的移除和配置的移除

```shell
# 移除Builder
./webman workbunny:rqueue-remove test --mode=queue
# 移除延迟Builder
./webman workbunny:rqueue-remove test --delayed--mode=queue

# 二级菜单同理
```

- 关闭

关闭仅对配置进行移除

```shell
# 关闭Builder
./webman workbunny:rqueue-remove test --close--mode=queue
# 关闭延迟Builder
./webman workbunny:rqueue-remove test --close--delayed--mode=queue

# 二级菜单同理
```

### GroupBuilder

- 一个GroupBuilder类对应一个消费Group和一个消费逻辑 ```QueueBuilder::handler()```
- 一个GroupBuilder可对应一个/多个Redis-Stream-Key，通过配置 ```QueueBuilder::$config['queues']```
- GroupBuilder类使用定时器进行消费，使用定时器释放当前 Stream-Key 上**所有Group收取过的闲置消息**
- 可以使用多个GroupBuilder类配置相同的 ```QueueBuilder::$config['queues']```，从而达到一条/多条队列由不同的消费逻辑进行处理

#### 命令行

- 创建
```shell
# 创建一个拥有单进程消费者的GroupBuilder
./webman workbunny:rqueue-builder test --mode=group
# 创建一个拥有4进程消费者的GroupBuilder
./webman workbunny:rqueue-builder test 4 --mode=group

# 创建一个拥有单进程消费者的延迟GroupBuilder
./webman workbunny:rqueue-builder test --delayed--mode=group
# 创建一个拥有4进程消费者的延迟GroupBuilder
./webman workbunny:rqueue-builder test 4 --delayed--mode=group

# 二级菜单

# 在 process/workbunny/rqueue 目录下创建 TestBuilder.php
./webman workbunny:rqueue-builder test --mode=group
# 在 process/workbunny/rqueue/project 目录下创建 TestBuilder.php
./webman workbunny:rqueue-builder project/test --mode=group
# 在 process/workbunny/rqueue/project 目录下创建 TestAllBuilder.php
./webman workbunny:rqueue-builder project/testAll --mode=group
```

- 移除

移除包含了类文件的移除和配置的移除

```shell
# 移除Builder
./webman workbunny:rqueue-remove test --mode=group
# 移除延迟Builder
./webman workbunny:rqueue-remove test --delayed--mode=group

# 二级菜单同理
```

- 关闭

关闭仅对配置进行移除

```shell
# 关闭Builder
./webman workbunny:rqueue-remove test --close--mode=group
# 关闭延迟Builder
./webman workbunny:rqueue-remove test --close--delayed--mode=group

# 二级菜单同理
```


### 注意

- **QueueBuilder与GroupBuilder在命令行自动生成时没有做类似Delayed的区分，用户可自行进行命名区分，如：**

```shell
# 创建一个GroupBuilder
./webman workbunny:rqueue-builder testGroup --mode=group
# 创建一个QueueBuilder
./webman workbunny:rqueue-builder testQueue --mode=queue
```

- **创建的Builder类可以手动修改调整**

- **为Builder添加进process.php的配置可以手动修改**

### 查看Builder

```shell
./webman workbunny:rqueue-list
```

**注：当 Builder 未启动时，handler 与 count 显示为 --**

```shell
+----------+-----------------------------------------------------------------------+-------------------------------------------------+-------+-------+
| name     | file                                                                  | handler                                         | count | mode  |
+----------+-----------------------------------------------------------------------+-------------------------------------------------+-------+-------+
| test     | /var/www/your-project/process/workbunny/rqueue/TestBuilder.php        | process\workbunny\rqueue\TestBuilder            | 1     | queue |
| test -d  | /var/www/your-project/process/workbunny/rqueue/TestBuilderDelayed.php | process\workbunny\rqueue\TestBuilderDelayed     | 1     | group |
+----------+-----------------------------------------------------------------------+-------------------------------------------------+-------+-------+
```

### 生产

#### 发布普通消息

**注：向普通队列发布延迟消息会抛出一个 WebmanRqueueException 异常**

```php
use function Workbunny\WebmanRqueue\sync_publish;
use process\workbunny\rqueue\TestBuilder;

# 使用函数发布
/** headers参数详见 @link Header */
sync_publish(TestBuilder::instance(), 'abc', [
	'_delete' => false
]);

# 使用对象发布
/** headers参数详见 @link Header */
TestBuilder::instance()->publish('abc', [
	'_delete' => false
]);
```

#### 发布延迟消息

**注：向延迟队列发布普通消息会抛出一个 WebmanRqueueException 异常**

```php
use function Workbunny\WebmanRqueue\sync_publish;
use process\workbunny\rqueue\TestBuilder;

# 延迟10ms
sync_publish(TestBuilder::instance(), 'abc', [
	'_delay' => 10
]);

# 延迟10ms
TestBuilder::instance()->publish('abc', [
	'_delay' => 10
]);
```

## 说明

- **小范围生产验证中，欢迎 [issue](https://github.com/workbunny/webman-rqueue/issues) 和 PR**；

- **Redis Stream** 本身没有 **delayed** 或 **non-delayed** 之分，组件代码将它们区分的原因是不希望 **delayed** 被滥用；开发者应该明确哪些消息是延迟的、哪些是立即的，并且明确体现，也方便维护，因为延迟消息过多会导致消息堆积，从而占用Redis过多的资源；

- **Redis Stream** 的持久化依赖 **Redis** 本身的持久化策略，在一定情况下 **Redis Stream** 也并非是可靠型的消息队列;关于持久化相关内容，请仔细阅读 **[Redis中文文档](http://www.redis.cn/topics/persistence.html)**；

