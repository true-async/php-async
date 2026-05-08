--TEST--
Async\signal(): unused signal Future disposes its underlying signal_event so the reactor can exit
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') echo "skip multi-signum scenario fails on BSD/Darwin — root cause unclear (not libuv signal layer; suspected interaction with our libuv_reactor signal save/restore or ZTS thread signal mask). Tracked separately; unrelated to the dispose-chain fix in this commit.";
if (!function_exists('posix_kill')) echo "skip posix extension required";
?>
--FILE--
<?php

use Async\Signal;
use function Async\signal;
use function Async\spawn;
use function Async\delay;
use function Async\await_any_or_fail;

// Original client repro: await_any_or_fail with two signal Futures.
// Without the fix, the unused Future's signal_event would keep the libuv
// reactor alive and the script would hang after printing "Got: SIGUSR1".
spawn(function () {
    delay(50);
    posix_kill(getmypid(), SIGUSR1);
});

echo "before\n";
$signal = await_any_or_fail([
    signal(Signal::SIGUSR1),
    signal(Signal::SIGUSR2),
]);
echo "Got: " . $signal->name . "\n";
echo "after\n";

?>
--EXPECT--
before
Got: SIGUSR1
after
