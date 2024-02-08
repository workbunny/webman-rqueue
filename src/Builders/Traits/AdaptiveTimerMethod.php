<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use Closure;
use Workbunny\WebmanRqueue\Exceptions\WebmanRqueueException;
use Workerman\Events\EventInterface;
use Workerman\Worker;

trait AdaptiveTimerMethod
{
    /** @var int 闲置阈值 ms */
    protected int $idleThreshold = 0;

    /** @var int 退避指数 */
    protected int $avoidIndex = 0;

    /** @var float 最大定时器间隔 ms */
    protected float $maxTimerInterval = 0.0;

    /** @var int|null 最后一次获取消息的毫秒时间戳 */
    private static ?int $lastMessageMilliTimestamp = null;

    /** @var bool 是否到达最大间隔 */
    private static bool $isMaxTimerInterval = false;

    /** @var array 定时器映射 */
    private static array $timerIdMap = [];

    /**
     * 获取最后一次获得消息的毫秒时间戳
     *
     * @return int|null
     */
    public static function getLastMessageMilliTimestamp(): ?int
    {
        return self::$lastMessageMilliTimestamp;
    }

    /**
     * 是否达到最大间隔
     *
     * @return bool
     */
    public static function isMaxTimerInterval(): bool
    {
        return self::$isMaxTimerInterval;
    }

    /**
     * 获取定时器映射表
     *
     * @return array
     */
    public static function getTimerIdMap(): array
    {
        return self::$timerIdMap;
    }

    /**
     * 获取闲置阈值
     *
     * @return int
     */
    public function getIdleThreshold(): int
    {
        return $this->idleThreshold;
    }

    /**
     * 设置闲置阈值
     *
     * @param int $idleThreshold
     */
    public function setIdleThreshold(int $idleThreshold): void
    {
        $this->idleThreshold = $idleThreshold;
    }

    /**
     * 获取退避指数
     *
     * @return int
     */
    public function getAvoidIndex(): int
    {
        return $this->avoidIndex;
    }

    /**
     * 设置退避指数
     *
     * @param int $avoidIndex
     */
    public function setAvoidIndex(int $avoidIndex): void
    {
        $this->avoidIndex = $avoidIndex;
    }

    /**
     * 获取最大间隔
     *
     * @return float
     */
    public function getMaxTimerInterval(): float
    {
        return $this->maxTimerInterval;
    }

    /**
     * 设置最大间隔
     *
     * @param float $maxTimerInterval
     */
    public function setMaxTimerInterval(float $maxTimerInterval): void
    {
        $this->maxTimerInterval = $maxTimerInterval;
    }

    /**
     * 添加自适应退避定时器
     *
     * @param int $millisecond
     * @param Closure $func
     * @param mixed ...$args
     * @return string
     */
    public function adaptiveTimerCreate(int $millisecond, Closure $func, mixed ...$args): string
    {
        if (!Worker::$globalEvent) {
            throw new WebmanRqueueException("Event driver error. ", -1);
        }
        // 初始化上一次获取信息的毫秒时间戳
        self::$lastMessageMilliTimestamp = self::$lastMessageMilliTimestamp ?? intval(microtime(true) * 1000);
        // 增加定时器
        $id = spl_object_hash($func);
        self::$timerIdMap[$id] = Worker::$globalEvent->add(
            $millisecond / 1000,
            EventInterface::EV_TIMER,
            $callback = function (...$args) use ($func, $millisecond, $id, &$callback)
            {
                $nowMilliTimestamp = intval(microtime(true) * 1000);
                if (\call_user_func($func, ...$args)) {
                    self::$lastMessageMilliTimestamp = $nowMilliTimestamp;
                    self::$isMaxTimerInterval = false;
                }
                if (
                    // 设置了闲置阈值、退避指数、最大时间间隔大于定时器初始时间间隔
                    $this->avoidIndex > 0 and $this->idleThreshold and $this->maxTimerInterval > $millisecond and
                    // 闲置超过闲置阈值
                    $nowMilliTimestamp - self::$lastMessageMilliTimestamp > $this->avoidIndex and
                    // 非最大间隔
                    !self::$isMaxTimerInterval
                ) {
                    $interval = min($this->avoidIndex * $millisecond, $this->maxTimerInterval);
                    // 如果到达最大值
                    if ($interval >= $this->maxTimerInterval) {
                        self::$isMaxTimerInterval = true;
                    }
                    // 移除之前的定时器
                    Worker::$globalEvent->del(self::$timerIdMap[$id], EventInterface::EV_TIMER);
                    // 新建定时器
                    self::$timerIdMap[$id] = Worker::$globalEvent->add($interval, EventInterface::EV_TIMER, $callback);
                }
            },
            $args
        );
        return $id;
    }

    /**
     * 移除自适应定时器
     *
     * @param string|null $id
     * @return void
     */
    public function adaptiveTimerDelete(?string $id = null): void
    {
        if (!Worker::$globalEvent) {
            throw new WebmanRqueueException("Event driver error. ", -1);
        }
        if (!$id) {
            foreach(self::$timerIdMap as $id) {
                Worker::$globalEvent->del(
                    $id, EventInterface::EV_TIMER);
            }
            self::$timerIdMap = [];
        } else {
            if ($id = self::$timerIdMap[$id] ?? null) {
                Worker::$globalEvent->del(
                    $id, EventInterface::EV_TIMER);
                unset(self::$timerIdMap[$id]);
            }
        }
    }
}