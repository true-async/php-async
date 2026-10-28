--TEST--
proc_close() cancelled while the child runs: the child is reaped when it exits, and the script does not wait for it
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
if (!is_dir('/proc/self')) die('skip /proc is required');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

function state(int $pid): string {
    $stat = @file_get_contents("/proc/$pid/stat");
    // The command name is in parentheses and may contain spaces; the state follows it.
    return $stat === false ? 'gone' : explode(' ', substr($stat, strrpos($stat, ')') + 2))[0];
}

$pid = 0;
$c = spawn(function () use (&$pid) {
    $p = proc_open(['sleep', '1'], [], $pipes);
    $pid = proc_get_status($p)['pid'];
    proc_close($p);
});
spawn(function () use ($c) {
    delay(100);
    $c->cancel();
});

try {
    await($c);
} catch (Async\AsyncCancellation $e) {
    echo "cancelled\n";
}

delay(1500);
echo "after the child exits: ", state($pid), "\n";

// A child that outlives the script does not delay its end.
$start = microtime(true);
$c = spawn(function () {
    $p = proc_open(['sleep', '5'], [], $pipes);
    proc_close($p);
});
spawn(function () use ($c) {
    delay(50);
    $c->cancel();
});

try {
    await($c);
} catch (Async\AsyncCancellation $e) {
    echo "cancelled\n";
}

register_shutdown_function(function () use ($start) {
    echo microtime(true) - $start < 3 ? "no wait\n" : "waited\n";
});
?>
--EXPECT--
cancelled
after the child exits: gone
cancelled
no wait
