--TEST--
ThreadChannel: closure transfer between threads
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $ch = new ThreadChannel(4);

    // Simple closure
    $ch->send(fn() => 42);

    // Closure with args
    $ch->send(fn(int $a, int $b) => $a + $b);

    // Closure with captured variable
    $multiplier = 10;
    $ch->send(fn(int $x) => $x * $multiplier);

    $t = spawn_thread(function() use ($ch) {
        $fn1 = $ch->recv();
        echo $fn1() . "\n";

        $fn2 = $ch->recv();
        echo $fn2(10, 20) . "\n";

        $fn3 = $ch->recv();
        echo $fn3(7) . "\n";
    });

    await($t);
    echo "Done\n";
});
?>
--EXPECT--
42
30
70
Done
