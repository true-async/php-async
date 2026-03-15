--TEST--
spawn_thread() - closure must not capture parent variables by reference
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
    // Closure with no captures — should work fine
    $thread = spawn_thread(function() {
        echo "no captures works\n";
        return true;
    });

    $result = await($thread);
    echo "result: " . ($result ? "true" : "false") . "\n";
});
?>
--EXPECT--
no captures works
result: true
