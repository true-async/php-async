--TEST--
ThreadPool: cancel() while worker is parked at concurrency limit
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php
use Async\ThreadPool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

spawn(function () {
    // 1 worker, concurrency=2, 5 tasks × 500ms. cancel() after ~50ms.
    // Worker parks at limit; in-flight task coroutines get cancelled via
    // pool_scope cascade once worker wakes, queued ones rejected.
    $pool = new ThreadPool(workers: 1, coroutine: true, concurrency: 2);
    $futures = [];
    for ($i = 0; $i < 5; $i++) {
        $futures[] = $pool->submit(static function () { delay(500); return 1; });
    }
    spawn(static function () use ($pool) {
        delay(50);
        $pool->cancel();
    });
    $cancelled = 0;
    foreach ($futures as $f) {
        try { await($f); }
        catch (Throwable $e) { $cancelled++; }
    }
    echo "cancelled=$cancelled\n";
});
?>
--EXPECT--
cancelled=5
