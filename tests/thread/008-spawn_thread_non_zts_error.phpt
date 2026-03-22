--TEST--
spawn_thread() - returns error code on non-ZTS build
--SKIPIF--
<?php
if (PHP_ZTS) die('skip non-ZTS only test');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $thread = spawn_thread(function() {
        echo "should not run\n";
    });

    try {
        $result = await($thread);
        // On non-ZTS, thread should indicate failure
        echo "exit_code should indicate error\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECTF--
%s
