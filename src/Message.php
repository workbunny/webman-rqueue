<?php
declare(strict_types=1);

namespace Workbunny\WebmanRqueue;

use Workbunny\WebmanRqueue\Protocols\AbstractMessage;

class Message extends AbstractMessage
{

    /**
     * @param array $message = [
     *  'group_name' => '',
     *  'queue_name' => '',
     *  'queue_size' => '',
     *  'prefetch_count' => '',
     *  'is_delayed' => '',
     * ]
     */
    public function __construct(array $message)
    {

        $this->setQueue($message['queue_name'] ?? $this->getQueue());
        $this->setGroup($message['group_name'] ?? $this->getGroup());
        $this->setQueueSize($message['queue_size'] ?? $this->getQueueSize());
        $this->setPrefetchCount($message['prefetch_count'] ?? $this->getPrefetchCount());
        $this->setDelayed($message['is_delayed'] ?? $this->isDelayed());
    }
}