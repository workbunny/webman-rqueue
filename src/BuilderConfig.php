<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue;

class BuilderConfig
{
    protected string $_queue = '';
    protected string $_body = '';
    protected string $_group = '';
    protected int $_queueSize = 4096;
    protected ?int $_prefetchCount = null;
    protected bool $_delayed = false;
    protected $_callback;

    /**
     * @return int|null
     */
    public function getPrefetchCount(): ?int
    {
        return $this->_prefetchCount;
    }

    /**
     * @param int|null $prefetchCount
     * @return void
     */
    public function setPrefetchCount(?int $prefetchCount): void
    {
        $this->_prefetchCount = $prefetchCount;
    }

    /**
     * @return int
     */
    public function getQueueSize(): int
    {
        return $this->_queueSize;
    }

    /**
     * @param int $queueSize
     */
    public function setQueueSize(int $queueSize): void
    {
        $this->_queueSize = $queueSize;
    }

    /**
     * @return bool
     */
    public function isDelayed(): bool
    {
        return $this->_delayed;
    }

    /**
     * @param bool $delayed
     */
    public function setDelayed(bool $delayed): void
    {
        $this->_delayed = $delayed;
    }



    public function getQueue(): string
    {
        return $this->_queue;
    }

    public function setQueue(string $queue): void
    {
        $this->_queue = $queue;
    }


    public function getBody(): string
    {
        return $this->_body;
    }

    public function setBody(string $body): void
    {
        $this->_body = $body;
    }


    public function getGroup(): string
    {
        return $this->_group;
    }

    public function setGroup(string $group): void
    {
        $this->_group = $group;
    }

    public function setCallback(callable $callback) : void
    {
        $this->_callback = $callback;
    }

    public function getCallback() : callable
    {
        return $this->_callback;
    }
}