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
use Workbunny\WebmanRqueue\Builders\AbstractBuilder;
use function Workbunny\WebmanRqueue\base_path;
use function Workbunny\WebmanRqueue\config;

class WorkbunnyWebmanRqueueList extends AbstractCommand
{
    protected static $defaultName        = 'workbunny:rqueue-list';
    protected static $defaultDescription = 'Show workbunny/webman-rqueue Builders list. ';


    protected function configure()
    {
        $this->setName('workbunny:rqueue-list')
            ->setDescription('Show workbunny/webman-rqueue Builders list. ');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $headers = ['name', 'file', 'handler', 'count', 'mode'];
        $rows = [];
        $files = $this->files(base_path() . '/' . self::$baseProcessPath);
        $configs = config('plugin.workbunny.webman-rqueue.process', []);

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $key = AbstractBuilder::getName(
                str_replace('/', '\\',
                    str_replace(base_path() . '/' , '', $fileName = $file->getPath() . '/' . $file->getBasename('.php'))
                )
            );
//            $key = str_replace(
//                '/',
//                '.',
//                str_replace(base_path() . '/' , '', $fileName = $file->getPath() . '/' . $file->getBasename('.php'))
//            );
            $name = str_replace(base_path() . '/' . self::$baseProcessPath . '/', '', $fileName);
            $rows[] = [
                strtolower(
                    strpos($name, 'BuilderDelayed') ?
                        str_replace('BuilderDelayed', ' -d', $name) :
                        str_replace('Builder', '', $name)
                ),
                $file->getRealPath(),
                $configs[$key]['handler'] ?? '--',
                $configs[$key]['count'] ?? '--',
                $configs[$key]['mode'] ?? '--'
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
        if(is_dir($path)){
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
        }
        return $files;
    }
}
