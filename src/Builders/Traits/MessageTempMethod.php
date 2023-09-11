<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use support\Db;
use Workerman\Timer;
use function Workbunny\WebmanRqueue\config;

trait MessageTempMethod
{
    protected ?int $_requeueTimer= null;

    protected function _tempInit(): void
    {
        if (config('database.plugin.workbunny.webman-rqueue.local-storage')) {
            $builder = Schema::connection('plugin.workbunny.webman-rqueue.local-storage');
            if (!$builder->hasTable('temp')) {
                $builder->create('temp', function (Blueprint $table) {
                    $table->id();
                    $table->string('queue');
                    $table->json('data');
                    $table->integer('create_at');
                });
                echo 'local-storage db created. ' . PHP_EOL;
            }
        }
    }

    protected function _tempInsert(string $queue, array $value): int
    {
        if (config('database.plugin.workbunny.webman-rqueue.local-storage')) {
            // 数据储存至文件
            return Db::connection('plugin.workbunny.webman-rqueue.local-storage')
                ->table('temp')->insertGetId([
                    'queue'      => $queue,
                    'data'       => json_encode($value, JSON_UNESCAPED_UNICODE),
                    'created_at' => time()
                ]);
        }
        return 0;
    }

    protected function _tempRequeue(): void
    {
        if (config('database.plugin.workbunny.webman-rqueue.local-storage')) {
            // 设置消息重载定时器
            if (($interval = config('plugin.workbunny.webman-rqueue.app.requeue_interval', 0)) > 0) {
                $this->_requeueTimer = Timer::add(
                    $interval,
                    function () {
                        $connection = Db::connection('plugin.workbunny.webman-rqueue.local-storage');
                        $connection->table('temp')->select()->chunkById(500, function (Collection $collection) use ($connection) {
                            $client = $this->getConnection()->client();
                            foreach ($collection as $item) {
                                if ($client->xAdd($item->queue,'*', json_decode($item->data, true))) {
                                    $connection->table('temp')->delete($item->id);
                                }
                            }
                        });
                    });
            }
        }
    }
}