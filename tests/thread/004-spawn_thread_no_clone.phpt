--TEST--
spawn_thread() - inherit: false — parent's functions are NOT available
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

function parent_only_func(): string {
    return "should not be accessible";
}

spawn(function() {
    $thread = spawn_thread(function() {
        // parent_only_func() should NOT be available with inherit=false
        if (function_exists('parent_only_func')) {
            echo "ERROR: function should not exist\n";
        } else {
            echo "function correctly unavailable\n";
        }
    }, inherit: false);

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
function correctly unavailable
done
