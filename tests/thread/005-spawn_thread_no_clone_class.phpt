--TEST--
spawn_thread() - inherit: false — parent's classes are NOT available
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

class ParentOnlyClass {
    public string $value = "test";
}

spawn(function() {
    $thread = spawn_thread(function() {
        if (class_exists('ParentOnlyClass', false)) {
            echo "ERROR: class should not exist\n";
        } else {
            echo "class correctly unavailable\n";
        }
    }, inherit: false);

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
class correctly unavailable
done
