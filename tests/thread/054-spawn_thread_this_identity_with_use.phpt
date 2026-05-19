--TEST--
spawn_thread() - $this and the same object captured via use() share identity in worker
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
    public int $n = 0;

    public function run(\Closure $boot): string {
        $self = $this;
        $t = spawn_thread(function() use ($self) {
            $same = ($this === $self) ? 'yes' : 'no';
            return "same={$same} n=".$this->n;
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class C { public int $n = 0; }');
};

spawn(function() use ($boot) {
    $obj = new C();
    $obj->n = 42;
    echo $obj->run($boot), "\n";
});
?>
--EXPECT--
same=yes n=42
