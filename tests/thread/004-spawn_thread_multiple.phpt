--TEST--
spawn_thread() - multiple threads run concurrently
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $t1 = spawn_thread(function() {
        return "thread-1";
    });

    $t2 = spawn_thread(function() {
        return "thread-2";
    });

    $t3 = spawn_thread(function() {
        return "thread-3";
    });

    $r1 = await($t1);
    $r2 = await($t2);
    $r3 = await($t3);

    echo "$r1\n";
    echo "$r2\n";
    echo "$r3\n";
});
?>
--EXPECT--
thread-1
thread-2
thread-3
