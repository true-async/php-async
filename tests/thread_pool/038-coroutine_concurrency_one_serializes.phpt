--TEST--
ThreadPool: concurrency=1 serializes tasks per worker
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
    // 1 worker, concurrency=1, 4 tasks × 50ms → must run sequentially ~200ms.
    $pool = new ThreadPool(workers: 1, coroutine: true, concurrency: 1);
    $t0 = microtime(true);
    $futures = [];
    for ($i = 0; $i < 4; $i++) {
        $futures[] = $pool->submit(static function () { delay(50); return 1; });
    }
    foreach ($futures as $f) await($f);
    $elapsed = (int)((microtime(true) - $t0) * 1000);
    echo ($elapsed >= 180 && $elapsed < 280) ? "sequential_ok\n" : "BAD ($elapsed)\n";
    $pool->close();
});
?>
--EXPECT--
sequential_ok
