--TEST--
ThreadPool: concurrency limits in-flight task coroutines per worker
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
    // 1 worker, concurrency=2, 6 tasks × 100ms.
    // Expect: tasks run in 3 batches of 2 → total ~300ms (not ~100, not ~600).
    $pool = new ThreadPool(workers: 1, coroutine: true, concurrency: 2);
    $t0 = microtime(true);
    $futures = [];
    for ($i = 0; $i < 6; $i++) {
        $futures[] = $pool->submit(static function () { delay(100); return 1; });
    }
    $sum = 0;
    foreach ($futures as $f) $sum += await($f);
    $elapsed = (int)((microtime(true) - $t0) * 1000);
    echo "sum=$sum\n";
    echo ($elapsed >= 250 && $elapsed < 500) ? "elapsed_ok\n" : "elapsed_BAD ($elapsed)\n";
    $pool->close();
});
?>
--EXPECT--
sum=6
elapsed_ok
