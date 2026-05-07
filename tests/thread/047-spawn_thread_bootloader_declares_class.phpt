--TEST--
spawn_thread() - bootloader declaring user class for typed closure args
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

class Msg {
    public function __construct(public readonly string $text) {}
}

$bootloader = static function(): void {
    class Msg {
        public function __construct(public readonly string $text) {}
    }
};

spawn(function() use ($bootloader) {
    $handler = static fn(Msg $m): string => "got:{$m->text}";

    $thread = spawn_thread(
        task: static function() use ($handler): string {
            return $handler(new Msg('hello'));
        },
        bootloader: $bootloader,
    );

    echo await($thread) . "\n";
});
?>
--EXPECT--
got:hello
