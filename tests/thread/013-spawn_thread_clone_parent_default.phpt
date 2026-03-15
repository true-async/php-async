--TEST--
spawn_thread() - clone_parent defaults to true
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

function helper_default_test(): string {
    return "accessible";
}

class DefaultTestClass {
    public function value(): int { return 99; }
}

spawn(function() {
    // No explicit clone_parent argument — should default to true
    $thread = spawn_thread(function() {
        echo helper_default_test() . "\n";
        $obj = new DefaultTestClass();
        echo $obj->value() . "\n";
    });

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
accessible
99
done
