--TEST--
Bailout: a coroutine cancelled while a memory bailout unwinds the thread is taken off the queue
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\\spawn_thread')) die('skip spawn_thread not available');
?>
--INI--
memory_limit=2M
--FILE--
<?php
/*
 * Inside a thread a coroutine exhausts memory, and the bailout unwinds every
 * coroutine of the thread. A shutdown function run during that unwinding
 * cancels a coroutine that is still waiting, which puts it in the queue; the
 * unwinding must take it off the queue before it unwinds it, or the scheduler
 * finds a finished coroutine queued ("deferred coroutines were not executed",
 * and in the server a second run on a freed context).
 */
use function Async\spawn_thread;
use function Async\await;

$t = spawn_thread(function () {
    $a = \Async\spawn(function () {
        echo "A waits\n";
        \Async\delay(10000);
        echo "A should not reach\n";
    });

    register_shutdown_function(function () use ($a) {
        echo "thread shutdown: A completed=", var_export($a->isCompleted(), true),
             " suspended=", var_export($a->isSuspended(), true), "\n";
        $a->cancel();
        echo "thread shutdown: after cancel: queued=", var_export($a->isQueued(), true), "\n";
    });

    \Async\spawn(function () {
        \Async\delay(10);
        echo "B allocates\n";
        $data = str_repeat('x', 10000000);
        echo "B should not reach\n";
    });

    echo "thread main waits\n";
    \Async\delay(5000);
    echo "thread main should not reach\n";
});

try {
    await($t);
    echo "thread ended\n";
} catch (\Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
?>
--EXPECTF--
thread main waits
A waits
B allocates

Fatal error: Allowed memory size of 2097152 bytes exhausted %s
thread shutdown: A completed=false suspended=true
thread shutdown: after cancel: queued=true
Async\ThreadTransferException: Allowed memory size of 2097152 bytes exhausted %s
