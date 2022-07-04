<?php
declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Commands;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WorkbunnyWebmanRqueueList extends AbstractCommand
{
    protected static $defaultName        = 'workbunny:rqueue-list';
    protected static $defaultDescription = 'Show workbunny/webman-rqueue Builders list. ';

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $headers = ['name', 'file', 'handler', 'count'];
        $rows = [];
        $files = $this->files(base_path() . '/' . $this->baseProcessPath);
        $configs = config('plugin.workbunny.webman-rqueue.process', []);

        /** @var SplFileInfo $file */
        foreach ($files as $file){
            $file = $file->getPath() . DIRECTORY_SEPARATOR . $file->getFilename();
            $name = str_replace('/', '.', str_replace($this->baseProcessPath, '', $file));
            $rows[] = [
                strpos($name,'Delayed') ? str_replace('BuilderDelayed','', $name) : str_replace('Builder','', $name),
                $file->getPath() . DIRECTORY_SEPARATOR . $file->getFilename(),
                $configs[$name]['handler'] ?? '--',
                $configs[$name]['count'] ?? '--'
            ];
        }

        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }

    /**
     * @param string $path
     * @return array
     */
    protected function files(string $path): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::FOLLOW_SYMLINKS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if($file->getExtension() !== 'php' and $file->isDir()){
                continue;
            }
            if(!strpos($file->getFilename(), 'Builder') and !strpos($file->getFilename(), 'BuilderDelayed')){
                continue;
            }
            $files[] = $file;
        }
        return $files;
    }
}
