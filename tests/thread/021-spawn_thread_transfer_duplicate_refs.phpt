--TEST--
spawn_thread() - same array referenced twice preserves identity
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
        $shared = [1, 2, 3];
        return [
            'a' => $shared,
            'b' => $shared,
        ];
    }));

    echo implode(',', $result['a']) . "\n";
    echo implode(',', $result['b']) . "\n";
});
?>
--EXPECT--
1,2,3
1,2,3
