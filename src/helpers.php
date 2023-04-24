<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue;

use SplFileInfo;
use Webman\Config;
use Workbunny\WebmanRqueue\Builders\AbstractBuilder;
use Workbunny\WebmanRqueue\Builders\QueueBuilder;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;

/**
 * 同步生产
 * @param QueueBuilder $builder
 * @param string $body
 * @param array $headers
 * @return int|false
 * @throws WebmanRqueueException
 */
function sync_publish(AbstractBuilder $builder, string $body, array $headers = []) : int|false
{
    return $builder->publish($body, $headers);
}

/**
 * @param string|null $key
 * @param mixed|null $default
 * @return array|mixed|null
 */
function config(string $key = null, mixed $default = null): mixed
{
    if(AbstractBuilder::$debug) {
        Config::load(config_path());
        return Config::get($key, $default);
    }else{
        return \config($key, $default);
    }
}

/**
 * @return string
 */
function config_path(): string
{
    return AbstractBuilder::$debug ? __DIR__ . '/config' : \config_path();
}

/**
 * @return string
 */
function base_path(): string
{
    return AbstractBuilder::$debug ? dirname(__DIR__) : \base_path();
}

/**
 * @param string $path
 * @param bool $remove
 * @return bool
 */
function is_empty_dir(string $path, bool $remove = false): bool
{
    $dirIterator = new \RecursiveDirectoryIterator($path, \FilesystemIterator::FOLLOW_SYMLINKS);
    $iterator = new \RecursiveIteratorIterator($dirIterator);

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if($file->getFilename() !== '.' and $file->getFilename() !== '..'){
            if($file->isDir()){
                is_empty_dir($file->getPath());
            }else{
                return false;
            }
        }
    }
    if($remove){
        rmdir($path);
    }
    return true;
}