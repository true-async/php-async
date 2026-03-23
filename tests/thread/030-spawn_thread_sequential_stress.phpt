--TEST--
spawn_thread() - many sequential threads with string returns
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
    $results = [];
    for ($i = 0; $i < 10; $i++) {
        $results[] = await(spawn_thread(function() use ($i) {
            return "thread-$i:" . str_repeat('x', $i);
        }));
    }
    foreach ($results as $r) {
        echo $r . "\n";
    }
});
?>
--EXPECT--
thread-0:
thread-1:x
thread-2:xx
thread-3:xxx
thread-4:xxxx
thread-5:xxxxx
thread-6:xxxxxx
thread-7:xxxxxxx
thread-8:xxxxxxxx
thread-9:xxxxxxxxx
