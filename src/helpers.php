<?php
declare(strict_types=1);

namespace Workbunny\WebmanRqueue;


/**
 * 同步生产
 * @param FastBuilder $builder
 * @param string $body
 * @param int|null $delay
 * @return bool
 */
function sync_publish(FastBuilder $builder, string $body, ?int $delay = null) : bool
{
    $client = $builder->connection()->client();
    if($client->xLen($queue = $builder->getMessage()->getQueue()) >= $builder->getMessage()->getQueueSize()){
        return false;
    }
    $client->xAdd($queue,'*', [
        'delay' => $delay,
        'body' => $body,
        'timestamp' => microtime(true)
    ]);
    return true;
}