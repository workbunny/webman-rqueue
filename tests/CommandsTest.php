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
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder created." ,
            "✅ Builder TestBuilder created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, false));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder removed." ,
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
        // create
        $this->assertFalse($this->fileIsset($name, false));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -o");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "✅ Builder TestsBuilder created successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -c");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "✅ Builder TestsBuilder removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试多层级Builder的创建和移除
     * @return void
     */
    public function testBuilderMultilevelCreateAndRemove()
    {
        $name = 'test/test';
        // create
        $this->assertFalse($this->fileIsset($name, false));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder created." ,
            "✅ Builder TestBuilder created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, false));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder removed." ,
            "ℹ️ Empty dir removed.",
            "✅ Builder TestBuilder removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试多层级Builder的打开和关闭
     * @return void
     */
    public function testBuilderMultilevelOpenAndClose()
    {
        $name = 'test/tests';
        // create
        $this->assertFalse($this->fileIsset($name, false));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -o");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "✅ Builder TestsBuilder created successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -c");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "✅ Builder TestsBuilder removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, false));
    }

    /**
     * @testdox 测试DelayedBuilder的创建和移除
     * @return void
     */
    public function testDelayedBuilderCreateAndRemove()
    {
        $name = 'test';
        // create
        $this->assertFalse($this->fileIsset($name, true));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder created." ,
            "✅ Builder TestBuilderDelayed created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, true));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder removed." ,
            "✅ Builder TestBuilderDelayed removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, true));
    }

    /**
     * @testdox 测试多层级DelayedBuilder的创建和移除
     * @return void
     */
    public function testDelayedBuilderMultilevelCreateAndRemove()
    {
        $name = 'test/test';
        // create
        $this->assertFalse($this->fileIsset($name, true));
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-builder $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder created." ,
            "✅ Builder TestBuilderDelayed created successfully."
        ], $result);
        $this->assertTrue($this->fileIsset($name, true));
        // remove
        list($result, $status) = $this->exec("php bin/command workbunny:rqueue-remove $name -d");
        $this->assertEquals(0, $status);
        $this->assertEquals([
            "ℹ️ Run in debug mode!" ,
            "ℹ️ Config updated." ,
            "ℹ️ Builder removed." ,
            "ℹ️ Empty dir removed.",
            "✅ Builder TestBuilderDelayed removed successfully."
        ], $result);
        $this->assertFalse($this->fileIsset($name, true));
    }
}