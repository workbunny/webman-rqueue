<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use Redis;
use RedisException;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;
use Workerman\Worker;

trait MessageQueueMethod
{
    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueFullInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        return $this->getConnection()->client()->rawCommand('XINFO', 'STREAM', $queueName, 'FULL');
    }

    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        return $this->getConnection()->client()->xInfo('STREAM', $queueName);
    }

    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueConsumersInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        $groupName = $this->getBuilderConfig()->getGroup();
        return $this->getConnection()->client()->xInfo('CONSUMERS', $queueName, $groupName);
    }

    /**
     * @return array
     * @throws RedisException
     */
    public function getQueueGroupsInfo(): array
    {
        $queueName = $this->getBuilderConfig()->getQueue();
        $groupName = $this->getBuilderConfig()->getGroup();
        return $this->getConnection()->client()->xInfo('GROUPS', $queueName, $groupName);
    }

    /**
     * @return void
     * @throws RedisException
     */
    public function del(): void
    {
        if($groups = $this->getQueueGroupsInfo() and $info = $this->getQueueInfo()) {
            $firstId = array_keys($info['first-entry'])[0];
            $lastDeliveredId = $groups['last-delivered-id'];
            $queueName = $this->getBuilderConfig()->getQueue();
            $client = $this->getConnection()->client();
            $result = $client->xRange($queueName, $firstId, $lastDeliveredId, 100);
            foreach ($result as $id => $value) {
                if($id !== $lastDeliveredId) {
                    $client->xDel($queueName, [$id]);
                }
            }
        }

    }

    /**
     * @param string $body
     * @param array $headers
     * @return bool
     * @throws RedisException
     */
    public function publish(string $body, array $headers = []): bool
    {
        $client = $this->getConnection()->client();
        $header = $this->getHeader()->clean()->init($headers);
        $header->clean();
        $header->init($headers);
        if(
            ($header->_delay and !$this->getBuilderConfig()->isDelayed()) or
            (!$header->_delay and $this->getBuilderConfig()->isDelayed())
        ){
            throw new WebmanRqueueException('Invalid publish. ');
        }
        $queue = $this->getBuilderConfig()->getQueue();
        $queueSize = $this->getBuilderConfig()->getQueueSize();
        if($queueSize > 0) {
            $queueLen = $client->xLen($queue);
            if($queueLen >= $queueSize){
                return false;
            }
        }

        $client->xAdd($queue,'*', [
            '_header' => $header->toArray(),
            '_body'   => $body,
        ]);
        return true;
    }

    /**
     * @param Worker $worker
     * @param bool $del
     * @return void
     * @throws RedisException
     */
    public function consume(Worker $worker, bool $del = true): void
    {
        $client = $this->getConnection()->client();
        $builderConfig = $this->getBuilderConfig();
        $queueName = $builderConfig->getQueue();
        $groupName = $builderConfig->getGroup();
        $consumerName = "$groupName-$worker->id";
        // create group
        $client->xGroup('CREATE', $queueName, $groupName,'0', true);
        // group read
        if($res = $client->xReadGroup(
            $groupName, $consumerName, [$queueName => '>'], $builderConfig->getPrefetchCount(),
            $this->timerInterval >= 1 ? $this->timerInterval : null
        )) {
            $ids = [];
            // messages
            foreach ($res[$queueName] ?? [] as $id => $value){
                // drop
                if(!isset($value['_header']) or !isset($value['_body'])) {
                    $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                    continue;
                }
                // delay message
                $this->getHeader()->clean()->init($value['_header']);
                if(
                    $this->getBuilderConfig()->isDelayed() and $this->getHeader()->_delay > 0 and
                    (($this->getHeader()->_delay / 1000 + $this->getHeader()->_timestamp) - microtime(true)) > 0
                ){
                    // republish
                    $client->xAdd($queueName, '*', $this->getHeader()->toArray());
                    $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                    continue;
                }
                try {
                    // handler
                    if(!\call_user_func($this->getBuilderConfig()->getCallback(), $id, $value, $this->getConnection())) {
                        // false to republish
                        $this->getHeader()->_count = $this->getHeader()->_count + 1;
                        $client->xAdd($queueName, '*', $this->getHeader()->toArray());
                    }
                    $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                }catch (\Throwable $throwable) {
                    $this->getHeader()->_count = $this->getHeader()->_count + 1;
                    $this->getHeader()->_error = $throwable->getMessage();
                    $client->xAdd($queueName, '*', $this->getHeader()->toArray());
                    $client->xAck($queueName, $groupName, $this->idsAdd($ids, $id));
                }
            }
            // del
            if($del) { $client->xDel($queueName, $ids); }
        }
    }
}