--TEST--
spawn_thread() - basic thread spawn with closure
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

echo "start\n";

spawn(function() {
    $thread = spawn_thread(function() {
        echo "thread executed\n";
    });

    await($thread);
    echo "thread completed\n";
});

echo "end\n";
?>
--EXPECT--
start
end
thread executed
thread completed
