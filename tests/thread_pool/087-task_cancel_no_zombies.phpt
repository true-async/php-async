--TEST--
ThreadPool: cancelled tasks leave no coroutine behind on the worker
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
use function Async\get_coroutines;

spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    $base = await($pool->submit(static fn() => count(get_coroutines())));

    for ($i = 0; $i < 3; $i++) {
        $task = $pool->submit(static function() { delay(20000); return 'never'; });
        delay(150);
        $task->cancel();
        try { await($task); } catch (Async\AsyncCancellation $e) {}
        unset($task);
    }

    delay(300);
    $after = await($pool->submit(static fn() => count(get_coroutines())));

    echo 'same as before the cancellations: ', var_export($after === $base, true), "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
same as before the cancellations: true
Done
