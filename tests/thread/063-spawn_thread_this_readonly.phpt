--TEST--
spawn_thread() - $this with readonly property
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

class C {
    public function __construct(public readonly string $name) {}
    public function run(\Closure $boot): string {
        $t = spawn_thread(function(): string { return strtoupper($this->name); }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class C { public function __construct(public readonly string $name = "") {} }');
};

spawn(function() use ($boot) {
    $obj = new C("hello");
    echo $obj->run($boot), "\n";
    echo "parent: ", $obj->name, "\n";
});
?>
--EXPECT--
HELLO
parent: hello
