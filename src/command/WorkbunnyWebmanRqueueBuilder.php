<?php
declare(strict_types=1);

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WorkbunnyWebmanRqueueBuilder extends Command
{
    protected static $defaultName        = 'workbunny:rqueue-builder';
    protected static $defaultDescription = 'Create and initialize a workbunny/webman-rqueue Builder. ';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'builder name');
        $this->addArgument('count', InputArgument::REQUIRED, 'builder count');
        $this->addOption('delayed', 'd', InputOption::VALUE_NONE, 'Delayed mode');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $count = $input->getArgument('count');
        $delayed = $input->getOption('delayed');

        if (!($pos = strrpos($name, '/'))) {
            $name = $this->getClassName($name, $delayed);
            $file = "process/workbunny/rqueue/{$name}.php";
            $namespace = 'process\workbunny\rqueue';
        } else {
            $path = substr($name, 0, $pos) . '/workbunny/rqueue';
            $name = $this->getClassName(substr($name, $pos + 1), $delayed);
            $file = "{$path}/{$name}.php";
            $namespace = str_replace('/', '\\', $path);
        }

        $this->initBuilder($name, $namespace, (int)$count, $file, $output);

        return self::SUCCESS;
    }

    /**
     * @param string $name
     * @param bool $isDelayed
     * @return string
     */
    protected function getClassName(string $name, bool $isDelayed): string
    {
        $class = preg_replace_callback('/:([a-zA-Z])/', function ($matches) {
                return strtoupper($matches[1]);
            }, ucfirst($name)) . 'Builder';
        return $isDelayed ? $class . 'Delayed' : $class;
    }

    /**
     * @param string $name
     * @param string $namespace
     * @param int $count
     * @param string $file
     * @param OutputInterface $output
     * @return void
     */
    protected function initBuilder(string $name, string $namespace, int $count, string $file, OutputInterface $output)
    {
        if(file_exists($process = config_path() . '/plugin/workbunny/webman-rqueue/process.php')){
            $processConfig = file_get_contents($process);
            $processName = str_replace('\\', '.', $className = "$namespace\\$name");

            if(strpos($processConfig, $processName) === false){
                file_put_contents($process, preg_replace_callback('/(];)(?!.*\1)/',
                    function () use ($processName, $className, $count){
                        return <<<EOF

    '$processName' => [
        'handler' => \\$className::class,
        'count'   => $count
    ],
];
EOF;
                    }, $processConfig,1));

                $this->createBuilder($name, $namespace, $file);
                $output->writeln("<info>Builder {$name} created successfully. </info>");
                return;
            }
            $output->writeln("<error>Builder {$name} failed to create: Config already exists. </error>");
            return;
        }
        $output->writeln("<error>Builder {$name} failed to create: plugin/workbunny/webman-rabbitmq/process.php does not exist. </error>");
    }

    /**
     * @param string $name
     * @param string $namespace
     * @param string $file
     * @return void
     */
    protected function createBuilder(string $name, string $namespace, string $file)
    {
        $delayed = (substr($name, -strlen('Delayed')) === 'Delayed')
            ? 'protected bool $delayed = true;'
            : 'protected bool $delayed = false;';

        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $command_content = <<<doc
<?php
declare(strict_types=1);

namespace $namespace;

use Illuminate\Redis\Connections\Connection;
use Workbunny\WebmanRqueue\FastBuilder;

class TestBuilder extends FastBuilder
{
    // 默认的redis连接配置
    protected string \$connection = 'default';
    // 消费组QOS
    protected int \$prefetch_count = 1;
    // 队列最大数量
    protected int \$queue_size = 4096;
    // 是否延迟队列$
    $delayed
    
    public function handler(string \$body, Connection \$connection): string
    {
        // TODO: Implement handler() method.
    }
}
doc;
        file_put_contents($file, $command_content);
    }

}
