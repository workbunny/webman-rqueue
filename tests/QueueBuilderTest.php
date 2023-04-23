<?php declare(strict_types=1);

namespace Workbunny\Tests;

use Redis;
use RedisException;
use Workbunny\Tests\Builders\TestQueueBuilder;
use Workbunny\Tests\Builders\TestQueueBuilderDelayed;
use Workbunny\WebmanRqueue\Builders\AbstractBuilder;
use Workbunny\WebmanRqueue\Builders\QueueBuilder;
use Workbunny\WebmanRqueue\Headers;
use function Workbunny\WebmanRqueue\sync_publish;

final class QueueBuilderTest extends BaseTestCase
{
    protected ?QueueBuilder $_queueBuilder = null;
    protected ?QueueBuilder $_queueBuilderDelayed = null;
    protected function setUp(): void
    {
        AbstractBuilder::$debug = true;
        require_once __DIR__ . '/functions.php';
        $this->_queueBuilder = new TestQueueBuilder();
        $this->_queueBuilderDelayed = new TestQueueBuilderDelayed();
        parent::setUp();
    }

    protected function id(): string
    {
        return hrtime(true) . '-' . rand(100, 999);
    }

    protected function get(QueueBuilder $builder, string $id): bool|array|Redis
    {
        try {
            $client = $builder->getConnection()->client();
            return $client->xRange($builder->getBuilderConfig()->getQueue(), $id, $id);
        }catch (RedisException $exception) {
            return [];
        }
    }

    protected function del(QueueBuilder $builder, array $ids): bool|int|Redis
    {
        try {
            $client = $builder->getConnection()->client();
            return $client->xDel($builder->getBuilderConfig()->getQueue(), $ids);
        }catch (RedisException $exception) {
            return false;
        }
    }

    /**
     * @testdox 测试通过sync_publish函数发布消息
     * @return void
     */
    public function testPublishUseFunction(): void
    {
        // queue publish
        $result = sync_publish($this->_queueBuilder, 'test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertTrue($result);
        // xrange
        $result = $this->get($this->_queueBuilder, $id);
        // verify
        $this->assertArrayHasKey($id, $result);
        $this->assertEquals('test', $result[$id]['_body']);
        $this->assertContainsEquals([
            '_id' => $id
        ], (new Headers($result[$id]['_header']))->toArray());
        // del
        $this->del($this->_queueBuilder, array_keys($result));

        // publish
        $result = sync_publish($this->_queueBuilderDelayed, 'test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertTrue($result);
        // xrange
        $result = $this->get($this->_queueBuilderDelayed, $id);
        // verify
        $this->assertArrayHasKey($id, $result);
        $this->assertEquals('test', $result[$id]['_body']);
        $this->assertContainsEquals([
            '_id' => $id
        ], (new Headers($result[$id]['_header']))->toArray());
        // del
        $this->del($this->_queueBuilderDelayed, array_keys($result));
    }

    /**
     * @testdox 测试通过Builder发布消息
     * @return void
     */
    public function testPublishUseClass(): void
    {
        // publish
        $result = $this->_queueBuilder->publish('test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertTrue($result);
        // xrange
        $result = $this->get($this->_queueBuilder, $id);
        $this->assertArrayHasKey($id, $result);
        $this->assertEquals('test', $result[$id]['_body']);
        $this->assertContainsEquals([
            '_id' => $id
        ], (new Headers($result[$id]['_header']))->toArray());
        // del
        $this->del($this->_queueBuilder, array_keys($result));

        // publish
        $result = $this->_queueBuilderDelayed->publish( 'test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertTrue($result);
        // xrange
        $result = $this->get($this->_queueBuilderDelayed, $id);
        // verify
        $this->assertArrayHasKey($id, $result);
        $this->assertEquals('test', $result[$id]['_body']);
        $this->assertContainsEquals([
            '_id' => $id
        ], (new Headers($result[$id]['_header']))->toArray());
        // del
        $this->del($this->_queueBuilderDelayed, array_keys($result));
    }
}