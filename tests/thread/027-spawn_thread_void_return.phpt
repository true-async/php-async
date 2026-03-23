--TEST--
spawn_thread() - closure with no return value
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
    $thread = spawn_thread(function() {
        // intentionally no return
    });

    $result = await($thread);
    var_dump($result);
});
?>
--EXPECT--
NULL
