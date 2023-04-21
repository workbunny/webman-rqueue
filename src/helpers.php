<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue;

use RedisException;
use SplFileInfo;
use Webman\Config;
use Workbunny\WebmanRqueue\Builders\AbstractBuilder;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;

/**
 * 同步生产
/**
 * @param FastBuilder $builder
 * @param string $body
 * @param array $header = [
 *  @see Header
 * ]
 * @return bool
 * @throws RedisException
 */
function sync_publish(FastBuilder $builder, string $body, array $header = []) : bool
{
    $client = $builder->connection()->client();
    $header = new Header($header);
    if(
        ($header->_delay and !$builder->getMessage()->isDelayed()) or
        (!$header->_delay and $builder->getMessage()->isDelayed())
    ){
        throw new WebmanRqueueException('Invalid publish. ');
    }
    if($client->xLen($queue = $builder->getMessage()->getQueue()) >= $builder->getMessage()->getQueueSize()){
        return false;
    }
    $client->xAdd($queue,'*', [
        '_header' => $header->toArray(),
        '_body'   => $body,
    ]);
    return true;
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