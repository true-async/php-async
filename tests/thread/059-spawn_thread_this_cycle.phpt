--TEST--
spawn_thread() - $this with a self-cycle ($this->self === $this)
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
    public ?C $self = null;
    public int $n = 0;
    public function run(\Closure $boot): string {
        $t = spawn_thread(function(): string {
            $same = ($this === $this->self) ? 'yes' : 'no';
            return "same={$same} n=".$this->n;
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class C { public ?C $self = null; public int $n = 0; }');
};

spawn(function() use ($boot) {
    $c = new C();
    $c->self = $c;
    $c->n = 5;
    echo $c->run($boot), "\n";
});
?>
--EXPECT--
same=yes n=5
