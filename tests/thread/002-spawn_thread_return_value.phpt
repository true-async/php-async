--TEST--
spawn_thread() - thread returns a value via await
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
        return 42;
    });

    $result = await($thread);
    echo "result: $result\n";
});
?>
--EXPECT--
result: 42
