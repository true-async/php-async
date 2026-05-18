--TEST--
ThreadPool: stress — many tasks with low concurrency, all complete
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
    // 200 tasks, 2 workers × concurrency=4 = 8 in-flight max.
    // Each task does a tiny delay so coroutine scheduling actually runs.
    // Verifies park/wake cycle survives many iterations.
    $pool = new ThreadPool(workers: 2, queueSize: 16, coroutine: true, concurrency: 4);
    $futures = [];
    for ($i = 0; $i < 200; $i++) {
        $n = $i;
        $futures[] = $pool->submit(static function () use ($n) {
            delay(1);
            return $n * 2;
        });
    }
    $sum = 0;
    foreach ($futures as $i => $f) $sum += await($f);
    // Expected: 2 * (0+1+...+199) = 2 * 19900 = 39800.
    echo "sum=$sum\n";
    $pool->close();
});
?>
--EXPECT--
sum=39800
