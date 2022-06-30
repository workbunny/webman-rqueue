<?php
declare(strict_types=1);

namespace Workbunny\WebmanRqueue;


/**
 * 同步生产
 * @param FastBuilder $builder
 * @param string $body
 * @param array|null $headers
 * @return bool
 */
function sync_publish(FastBuilder $builder, string $body, ?array $headers = null) : bool
{
    $client = $builder->connection()->client();
    if($client->xLen($queue = $builder->getMessage()->getQueue()) >= $builder->getMessage()->getQueueSize()){
        return false;
    }
    $client->xAdd($queue,'*', ['header' => array_merge([
        'timestamp' => microtime(true)
    ], $headers ?? []), 'body' => $body]);
    return true;
}