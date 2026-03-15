--TEST--
spawn_thread() - clone_parent: true copies parent's user classes
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

class Greeter {
    public function greet(string $name): string {
        return "Hello, $name!";
    }
}

spawn(function() {
    $thread = spawn_thread(function() {
        $g = new Greeter();
        echo $g->greet("World") . "\n";
    });

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
Hello, World!
done
