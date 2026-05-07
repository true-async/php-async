--TEST--
spawn_thread() - task closure declaring class hits do_bind_class assert
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
    $thread = spawn_thread(static function(): string {
        class Greeter {
            public function hello(): string { return 'hi'; }
        }
        return (new Greeter())->hello();
    });

    echo await($thread) . "\n";
});
?>
--EXPECT--
hi
