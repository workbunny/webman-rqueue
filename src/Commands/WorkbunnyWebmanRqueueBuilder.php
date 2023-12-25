<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Workbunny\WebmanRqueue\Builders\AbstractBuilder;
use function Workbunny\WebmanRqueue\config_path;
use function Workbunny\WebmanRqueue\config;

class WorkbunnyWebmanRqueueBuilder extends AbstractCommand
{
    protected static $defaultName        = 'workbunny:rqueue-builder';
    protected static $defaultDescription = 'Create and initialize a workbunny/webman-rqueue Builder. ';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('workbunny:rqueue-builder')
            ->setDescription('Create and initialize a workbunny/webman-rqueue Builder. ');
        $this->addArgument('name', InputArgument::REQUIRED, 'Builder name. ');
        $this->addArgument('count', InputArgument::OPTIONAL, 'Number of processes started by builder. ', 1);
        $this->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Builder mode: queue, group', 'queue');
        $this->addOption('delayed', 'd', InputOption::VALUE_NONE, 'Delay mode builder. ');
        $this->addOption('open', 'o', InputOption::VALUE_NONE, 'Only update config. ');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = $input->getArgument('name');
        $count   = $input->getArgument('count');
        $delayed = $input->getOption('delayed');
        $open    = $input->getOption('open');
        $mode    = $input->getOption('mode');
        list($name, $namespace, $file) = $this->getFileInfo($name, $delayed);
        // check process.php
        if(!file_exists($process = config_path() . '/plugin/workbunny/webman-rqueue/process.php')) {
            return $this->error($output, "Builder {$name} failed to create: plugin/workbunny/webman-rqueue/process.php does not exist.");
        }
        $processName = AbstractBuilder::getName($className = "$namespace\\$name", true) . "-$mode";
        // check config
        $config = config('plugin.workbunny.webman-rqueue.process', []);
        if(isset($config[$processName])){
            return $this->error($output, "Builder {$name} failed to create: Config already exists.");
        }
        // get mode
        /** @var AbstractBuilder $builderClass */
        $builderClass = $this->getBuilder($mode);
        if($builderClass === null) {
            return $this->error($output, "Builder {$name} failed to create: Mode {$mode} does not exist.");
        }
        // config set
        if(\file_put_contents($process, \preg_replace_callback('/(];)(?!.*\1)/',
                function () use ($processName, $className, $count, $mode) {
                    return <<<DOC
    '$processName' => [
        'handler' => \\$className::class,
        'count'   => {$count},
        'mode'    => '$mode',
    ],
];
DOC;
                }, \file_get_contents($process),1)) !== false) {
            $this->info($output, "Config updated.");
        }
        if(!$open) {
            // dir create
            if (!\is_dir($path = \pathinfo($file, PATHINFO_DIRNAME))) {
                \mkdir($path, 0777, true);
            }
            // file create
            if(!\file_exists($file)){
                if(\file_put_contents($file, $builderClass::classContent(
                        $namespace, $name, \str_ends_with($name, 'Delayed')
                    )) !== false) {
                    $this->info($output, "Builder created.");
                }
            }
        }
        return $this->success($output, "Builder {$name} created successfully.");
    }
}
