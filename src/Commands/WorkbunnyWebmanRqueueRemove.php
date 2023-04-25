<?php
declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Workbunny\WebmanRqueue\Builders\AbstractBuilder;
use function Workbunny\WebmanRqueue\is_empty_dir;
use function Workbunny\WebmanRqueue\base_path;
use function Workbunny\WebmanRqueue\config_path;
use function Workbunny\WebmanRqueue\config;

class WorkbunnyWebmanRqueueRemove extends AbstractCommand
{
    protected static $defaultName        = 'workbunny:rqueue-remove';
    protected static $defaultDescription = 'Remove a workbunny/webman-rqueue Builder.';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'builder name.');
        $this->addOption('delayed', 'd', InputOption::VALUE_NONE, 'Delayed mode.');
        $this->addOption('close', 'c', InputOption::VALUE_NONE, 'Close only mode.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $delayed = $input->getOption('delayed');
        $close = $input->getOption('close');
        list($name, $namespace, $file) = $this->getFileInfo($name, $delayed);
        $file = $close ? '' : $file;
        if(!file_exists($process = config_path() . '/plugin/workbunny/webman-rqueue/process.php')) {
            return $this->error($output, "Builder {$name} failed to clear: plugin/workbunny/webman-rqueue/process.php does not exist.");
        }
        // remove config
        $config = config('plugin.workbunny.webman-rqueue.process', []);
        if(isset($config[$processName = AbstractBuilder::getName($className = "$namespace\\$name")])){
            if(\file_put_contents($process, \preg_replace_callback("/    '$processName' => [[\s\S]*?],\r\n/",
                        function () {
                            return '';
                        }, \file_get_contents($process),1)
                ) !== false) {
                $this->info($output, "Config updated.");
            }
        }
        if($file) {
            // remove file
            if(\file_exists($file)){
                \unlink($file);
                $this->info($output, "Builder removed.");
            }
            // remove empty dir
            if(\dirname($file) !== base_path() . DIRECTORY_SEPARATOR . self::$baseProcessPath) {
                is_empty_dir(\dirname($file), true);
                $this->info($output, "Empty dir removed.");
            }
        }
        return $this->success($output, "Builder $name removed successfully.");
    }
}
