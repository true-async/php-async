--TEST--
spawn_thread() - $this is a BackedEnum case; method calls through $this work
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

enum Color: string {
    case Red = 'r';
    case Green = 'g';
    case Blue = 'b';

    public function label(): string {
        return match($this) {
            Color::Red   => 'red',
            Color::Green => 'green',
            Color::Blue  => 'blue',
        };
    }

    public function run(\Closure $boot): string {
        $t = spawn_thread(function(): string {
            return get_class($this) . ':' . $this->value . '=' . $this->label();
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('enum Color: string { case Red = "r"; case Green = "g"; case Blue = "b"; public function label(): string { return match($this) { Color::Red => "red", Color::Green => "green", Color::Blue => "blue", }; } }');
};

spawn(function() use ($boot) {
    echo Color::Green->run($boot), "\n";
});
?>
--EXPECT--
Color:g=green
