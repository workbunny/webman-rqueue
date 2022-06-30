<?php
declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Protocols;

use Illuminate\Redis\Connections\Connection;
use Workerman\Worker;

interface BuilderInterface
{

    /**
     * @param Worker $worker
     * @return mixed
     */
    public function onWorkerStart(Worker $worker);

    /**
     * @param Worker $worker
     * @return mixed
     */
    public function onWorkerStop(Worker $worker);

    /**
     * @param Worker $worker
     * @return mixed
     */
    public function onWorkerReload(Worker $worker);

    /**
     * 消费响应
     * @param string $body
     * @param Connection $connection
     * @return bool
     */
    public function handler(string $body, Connection $connection) : bool;
}