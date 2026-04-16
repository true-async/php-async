--TEST--
spawn_thread() - same string in multiple places
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
    $result = await(spawn_thread(function() {
        $str = str_repeat('x', 100);
        return ['a' => $str, 'b' => $str, 'c' => [$str]];
    }));

    echo strlen($result['a']) . "\n";
    echo ($result['a'] === $result['b']) ? "equal\n" : "not equal\n";
    echo ($result['c'][0] === $result['a']) ? "equal\n" : "not equal\n";
});
?>
--EXPECT--
100
equal
equal
