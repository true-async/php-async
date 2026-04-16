--TEST--
spawn_thread() - closure with captured variables (use)
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
    $greeting = "hello";
    $multiplier = 10;
    $data = ['a', 'b', 'c'];

    $thread = spawn_thread(function() use ($greeting, $multiplier, $data) {
        return [
            'msg' => $greeting . ' world',
            'result' => 5 * $multiplier,
            'items' => $data,
        ];
    });

    $result = await($thread);
    echo $result['msg'] . "\n";
    echo $result['result'] . "\n";
    echo implode(',', $result['items']) . "\n";
});
?>
--EXPECT--
hello world
50
a,b,c
