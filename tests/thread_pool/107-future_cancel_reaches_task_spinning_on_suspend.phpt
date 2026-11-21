--TEST--
ThreadPool coroutine mode: Future::cancel() throws into a task that spins on suspend()
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\delay;

$file = sys_get_temp_dir() . '/tp107_' . getmypid() . '.txt';
@unlink($file);

spawn(function () use ($file) {
    $pool = new ThreadPool(1, 0, null, true);

    $future = $pool->submit(static function () use ($file) {
        $me = \Async\current_coroutine();

        try {
            while (true) {
                \Async\suspend();

                if ($me->isCancellationRequested()) {
                    file_put_contents($file, "cancellation requested, nothing thrown\n");
                    return 'returned normally';
                }
            }
        } catch (\Throwable $e) {
            file_put_contents($file, "thrown into the task: " . get_class($e) . "\n");
            throw $e;
        }
    });

    delay(300);
    $future->cancel();
    delay(500);

    echo file_exists($file) ? file_get_contents($file) : "the task wrote nothing\n";
    @unlink($file);

    $pool->close();
    echo "closed\n";
});
?>
--EXPECT--
thrown into the task: Async\AsyncCancellation
closed
