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

    /** @var float|null 定时器最初间隔 */
    private float|null $timerInitialInterval = null;

    /** @var int 最后一次获取消息的毫秒时间戳 */
    private static int $lastMessageMilliTimestamp = 0;

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
     * 获取初始间隔
     *
     * @return float|null
     */
    public function getTimerInitialInterval(): ?float
    {
        return $this->timerInitialInterval;
    }

    /**
     * 设置初始间隔
     *
     * @param float|null $timerInitialInterval
     */
    public function setTimerInitialInterval(?float $timerInitialInterval): void
    {
        $this->timerInitialInterval = $timerInitialInterval;
    }

    /**
     * 添加自适应退避定时器
     *
     *  - 执行函数正反馈时，定时器初始化重置
     *  - 执行函数负反馈时
     *      - 距上次正反馈的间隔时长大于闲置阈值且未到达最大间隔时，定时器间隔据退避指数累加
     *      - 距上次正反馈的间隔时长大于闲置阈值且达到最大间隔时，定时器间隔维持
     *      - 距上次正反馈的间隔时长小于等于闲置阈值时，定时器间隔维持
     *
     * @param Closure $func 执行函数
     * @param mixed ...$args 函数入参
     * @return string
     */
    public function adaptiveTimerCreate(Closure $func, mixed ...$args): string
    {
        if (!Worker::$globalEvent) {
            throw new WebmanRqueueException("Event driver error. ", -1);
        }
        $id = spl_object_hash($func);
        // 设置初始间隔
        $this->setTimerInitialInterval($this->getTimerInterval());
        // 创建定时器
        self::$timerIdMap[$id] = Worker::$globalEvent->add($this->getTimerInitialInterval(), EventInterface::EV_TIMER,
            $callback = function (...$args) use ($func, $id, &$callback)
            {
                // 获取毫秒时间戳
                $nowMilliTimestamp = intval(microtime(true) * 1000);
                // 是否开启自适应
                $enable = (
                    // 设置了退避指数、闲置阈值
                    $this->getAvoidIndex() > 0 and $this->getIdleThreshold() > 0 and
                    // 定时器间隔小于最大间隔
                    $this->getMaxTimerInterval() > $this->getTimerInitialInterval()
                );
                // 执行回调
                try {
                    if ($result = \call_user_func($func, ...$args)) {
                        // 设置执行时间
                        self::$lastMessageMilliTimestamp = $nowMilliTimestamp;
                    }
                } catch (\Throwable){
                    // 异常为负反馈
                    $result = false;
                }
                // 如果自适应开启
                if ($enable) {
                    // 正反馈
                    if ($result) {
                        // 归零
                        self::$isMaxTimerInterval = false;
                        // 重新设置定时器
                        $setTimer = true;
                        // 定时器初始化
                        $this->setTimerInterval($this->getTimerInitialInterval());
                    }
                    // 负反馈
                    else {
                        $setTimer = false;
                        if (
                            $nowMilliTimestamp - self::getLastMessageMilliTimestamp() > $this->getIdleThreshold() and // 闲置超过闲置阈值
                            !self::isMaxTimerInterval() // 非最大间隔
                        ) {
                            $interval = min($this->getAvoidIndex() * $this->getTimerInitialInterval(), $this->getMaxTimerInterval());
                            // 如果到达最大值
                            if ($interval >= $this->getMaxTimerInterval()) {
                                self::$isMaxTimerInterval = true;
                            }
                            $setTimer = true;
                            $this->setTimerInterval($interval);
                        }
                    }
                    // 是否需要设置定时器
                    if ($setTimer) {
                        // 移除定时器
                        self::adaptiveTimerDelete($id);
                        // 新建定时器
                        self::$timerIdMap[$id] = Worker::$globalEvent->add($this->getTimerInterval(), EventInterface::EV_TIMER, $callback);
                    }
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
        if ($id === null) {
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