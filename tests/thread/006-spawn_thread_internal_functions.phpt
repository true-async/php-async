--TEST--
spawn_thread() - internal PHP functions available in child thread
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
        // Internal functions should always be available
        echo strlen("hello") . "\n";
        echo strtoupper("world") . "\n";
        echo implode(",", [1, 2, 3]) . "\n";
    });

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
5
WORLD
1,2,3
done
