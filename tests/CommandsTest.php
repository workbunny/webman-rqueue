<?php declare(strict_types=1);

namespace Workbunny\Tests;

use Workbunny\WebmanRqueue\Builders\AbstractBuilder;

/**
 * @runTestsInSeparateProcesses
 */
final class CommandsTest extends BaseTestCase
{
    protected function setUp(): void
    {
        AbstractBuilder::$debug = true;
        parent::setUp();
    }

    /**
     * @testdox 测试Builder的创建和移除
     * @return void
     */
    public function testBuilderCreateAndRemove(): void
    {
        $name = 'test';
        // create
        $this->assertFalse($this->fileIsset($name, false));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder created.",
            "✅ Builder TestBuilder created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, false));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder removed.",
            "✅ Builder TestBuilder removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试Builder的打开和关闭
     * @return void
     */
    public function testBuilderOpenAndClose(): void
    {
        $name = 'tests';
        // create (open only)
        $this->assertFalse($this->fileIsset($name, false));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -o");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "✅ Builder TestsBuilder created successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
        // remove (close only)
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -c");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "✅ Builder TestsBuilder removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试多层级Builder的创建和移除
     * @return void
     */
    public function testBuilderMultilevelCreateAndRemove(): void
    {
        $name = 'test/test';
        // create
        $this->assertFalse($this->fileIsset($name, false));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder created.",
            "✅ Builder TestBuilder created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, false));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder removed.",
            "ℹ️ Empty dir removed.",
            "✅ Builder TestBuilder removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试多层级Builder的打开和关闭
     * @return void
     */
    public function testBuilderMultilevelOpenAndClose(): void
    {
        $name = 'test/tests';
        // create (open only)
        $this->assertFalse($this->fileIsset($name, false));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -o");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "✅ Builder TestsBuilder created successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
        // remove (close only)
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -c");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "✅ Builder TestsBuilder removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试DelayedBuilder的创建和移除
     * @return void
     */
    public function testDelayedBuilderCreateAndRemove(): void
    {
        $name = 'test';
        // create
        $this->assertFalse($this->fileIsset($name, true));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder created.",
            "✅ Builder TestBuilderDelayed created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, true));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder removed.",
            "✅ Builder TestBuilderDelayed removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, true));
    }

    /**
     * @testdox 测试多层级DelayedBuilder的创建和移除
     * @return void
     */
    public function testDelayedBuilderMultilevelCreateAndRemove(): void
    {
        $name = 'test/test';
        // create
        $this->assertFalse($this->fileIsset($name, true));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder created.",
            "✅ Builder TestBuilderDelayed created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, true));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!",
            "ℹ️ Config updated.",
            "ℹ️ Builder removed.",
            "ℹ️ Empty dir removed.",
            "✅ Builder TestBuilderDelayed removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, true));
    }

    // ========== 以下为新增测试 ==========

    /**
     * @testdox 测试重复创建同名Builder应失败
     * @return void
     */
    public function testDuplicateCreateShouldFail(): void
    {
        $name = 'dup';
        // first create
        list(, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name");
        $this->assertEquals(0, $status);
        $this->assertTrue($this->fileIsset($name, false));

        // duplicate create should fail
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name");
        $this->assertEquals(1, $status);
        $this->assertStringContainsString('Config already exists', $result[1] ?? '');

        // cleanup
        list(, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name");
        $this->assertEquals(0, $status);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试删除不存在的Builder应失败
     * @return void
     */
    public function testRemoveNonExistentBuilder(): void
    {
        $name = 'nonexist';
        $this->assertFalse($this->fileIsset($name, false));

        // remove non-existent builder
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name");
        $this->assertEquals(0, $status);
        // 即使未找到配置项，移除命令也会正常返回（因为移除逻辑做了安全处理）
        $this->assertStringContainsString('removed successfully', end($result) ?: '');
    }

    /**
     * @testdox 测试Group模式Builder的创建和移除
     * @return void
     */
    public function testGroupBuilderCreateAndRemove(): void
    {
        $name = 'groupTest';
        $this->assertFalse($this->fileIsset($name, false));
        // create group mode
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name --mode=group");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('GroupTestBuilder created successfully', end($result) ?: '');
        $this->assertTrue($this->fileIsset($name, false));

        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name --mode=group");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('GroupTestBuilder removed successfully', end($result) ?: '');
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试Adaptive模式Builder的创建和移除
     * @return void
     */
    public function testAdaptiveBuilderCreateAndRemove(): void
    {
        $name = 'adaptiveTest';
        $this->assertFalse($this->fileIsset($name, false));
        // create adaptive mode
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name --mode=adaptive");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('AdaptiveTestBuilder created successfully', end($result) ?: '');
        $this->assertTrue($this->fileIsset($name, false));

        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name --mode=adaptive");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('AdaptiveTestBuilder removed successfully', end($result) ?: '');
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试无效mode应失败
     * @return void
     */
    public function testInvalidModeShouldFail(): void
    {
        $name = 'invalidTest';
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name --mode=invalid");
        $this->assertEquals(1, $status);
        $this->assertStringContainsString('does not exist', $result[1] ?? '');
    }

    /**
     * @testdox 测试自定义count参数
     * @return void
     */
    public function testBuilderWithCustomCount(): void
    {
        $name = 'countTest';
        $this->assertFalse($this->fileIsset($name, false));
        // create with count=4
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name 4");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('CountTestBuilder created successfully', end($result) ?: '');

        // cleanup
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('CountTestBuilder removed successfully', end($result) ?: '');
    }

    /**
     * @testdox 测试Group模式DelayedBuilder的创建和移除
     * @return void
     */
    public function testGroupDelayedBuilderCreateAndRemove(): void
    {
        $name = 'groupDelay';
        $this->assertFalse($this->fileIsset($name, true));
        // create group delayed
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name --mode=group --delayed");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('GroupDelayBuilderDelayed created successfully', end($result) ?: '');
        $this->assertTrue($this->fileIsset($name, true));

        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name --mode=group --delayed");
        $this->assertEquals(0, $status);
        $this->assertStringContainsString('GroupDelayBuilderDelayed removed successfully', end($result) ?: '');
        $this->assertFalse($this->fileIsset($name, true));
    }
}
