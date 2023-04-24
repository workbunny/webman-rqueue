<?php declare(strict_types=1);

namespace Workbunny\Tests;

use Redis;
use RedisException;
use Workbunny\Tests\Builders\TestGroupBuilder;
use Workbunny\Tests\Builders\TestGroupBuilderDelayed;
use Workbunny\WebmanRqueue\Builders\AbstractBuilder;
use Workbunny\WebmanRqueue\Builders\GroupBuilder;
use Workbunny\WebmanRqueue\Headers;
use function Workbunny\WebmanRqueue\sync_publish;

final class GroupBuilderTest extends BaseTestCase
{
    protected ?GroupBuilder $_builder = null;
    protected ?GroupBuilder $_builderDelayed = null;
    protected function setUp(): void
    {
        AbstractBuilder::$debug = true;
        require_once __DIR__ . '/functions.php';
        $this->_builder = new TestGroupBuilder();
        $this->_builderDelayed = new TestGroupBuilderDelayed();
        parent::setUp();
    }

    protected function id(): string
    {
        return hrtime(true) . '-' . rand(100, 999);
    }

    protected function get(GroupBuilder $builder, string $id): array
    {
        try {
            $client = $builder->getConnection()->client();
            $result = [];
            foreach ($builder->getBuilderConfig()->getQueues() as $queue) {
                $result[$queue] = $client->xRange($queue, $id, $id);
            }
            return $result;
        }catch (RedisException $exception) {
            return [];
        }
    }

    protected function del(GroupBuilder $builder, array $ids): int|false
    {
        try {
            $client = $builder->getConnection()->client();
            $count = 0;
            foreach ($builder->getBuilderConfig()->getQueues() as $queue) {
                $client->xDel($queue, $ids);
                $count ++;
            }
            return $count;
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
        $result = sync_publish($this->_builder, 'test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertNotFalse($result);
        // xrange
        $result = $this->get($this->_builder, $id);
        // verify
        foreach ($this->_builder->getBuilderConfig()->getQueues() as $queue) {
            $this->assertArrayHasKey($id, $result[$queue]);
            $this->assertEquals('test', $result[$queue][$id]['_body']);
            $this->assertContainsEquals([
                '_id' => $id
            ], (new Headers($result[$queue][$id]['_header']))->toArray());
        }
        // del
        $this->del($this->_builder, array_keys($result));

        // publish
        $result = sync_publish($this->_builderDelayed, 'test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertNotFalse($result);
        // xrange
        $result = $this->get($this->_builderDelayed, $id);
        // verify
        foreach ($this->_builderDelayed->getBuilderConfig()->getQueues() as $queue) {
            $this->assertArrayHasKey($id, $result[$queue]);
            $this->assertEquals('test', $result[$queue][$id]['_body']);
            $this->assertContainsEquals([
                '_id' => $id
            ], (new Headers($result[$queue][$id]['_header']))->toArray());
        }
        // del
        $this->del($this->_builderDelayed, array_keys($result));
    }

    /**
     * @testdox 测试通过Builder发布消息
     * @return void
     */
    public function testPublishUseClass(): void
    {
        // publish
        $result = $this->_builder->publish('test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertNotFalse($result);
        // xrange
        $result = $this->get($this->_builder, $id);
        // verify
        foreach ($this->_builder->getBuilderConfig()->getQueues() as $queue) {
            $this->assertArrayHasKey($id, $result[$queue]);
            $this->assertEquals('test', $result[$queue][$id]['_body']);
            $this->assertContainsEquals([
                '_id' => $id
            ], (new Headers($result[$queue][$id]['_header']))->toArray());
        }
        // del
        $this->del($this->_builder, array_keys($result));

        // publish
        $result = $this->_builderDelayed->publish( 'test', [
            '_id' => $id = $this->id()
        ]);
        $this->assertNotFalse($result);
        // xrange
        $result = $this->get($this->_builderDelayed, $id);
        // verify
        foreach ($this->_builderDelayed->getBuilderConfig()->getQueues() as $queue) {
            $this->assertArrayHasKey($id, $result[$queue]);
            $this->assertEquals('test', $result[$queue][$id]['_body']);
            $this->assertContainsEquals([
                '_id' => $id
            ], (new Headers($result[$queue][$id]['_header']))->toArray());
        }
        // del
        $this->del($this->_builderDelayed, array_keys($result));
    }
}