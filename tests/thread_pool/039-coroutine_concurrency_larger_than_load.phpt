--TEST--
ThreadPool: concurrency > workload (limit never binds) ≈ unlimited
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
    // 2 workers, concurrency=100, only 4 tasks × 50ms → all parallel ~50ms.
    $pool = new ThreadPool(workers: 2, coroutine: true, concurrency: 100);
    $t0 = microtime(true);
    $futures = [];
    for ($i = 0; $i < 4; $i++) {
        $futures[] = $pool->submit(static function () { delay(50); return 1; });
    }
    foreach ($futures as $f) await($f);
    $elapsed = (int)((microtime(true) - $t0) * 1000);
    echo ($elapsed >= 40 && $elapsed < 120) ? "parallel_ok\n" : "BAD ($elapsed)\n";
    $pool->close();
});
?>
--EXPECT--
parallel_ok
