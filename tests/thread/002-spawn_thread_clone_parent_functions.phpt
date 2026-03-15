--TEST--
spawn_thread() - clone_parent: true copies parent's user functions
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

function my_helper(): string {
    return "hello from helper";
}

spawn(function() {
    $thread = spawn_thread(function() {
        // my_helper() should be available because clone_parent defaults to true
        echo my_helper() . "\n";
    });

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
hello from helper
done
