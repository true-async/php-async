--TEST--
spawn_thread() - $this with WeakReference property; identity preserved when target reachable
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

class Target { public int $n = 0; }
class Owner {
    public ?Target $target = null;
    public ?\WeakReference $ref = null;
    public function run(\Closure $boot): string {
        $t = spawn_thread(function(): string {
            $got = $this->ref?->get();
            $same = ($got !== null && $got === $this->target) ? 'yes' : 'no';
            $n = $got?->n ?? -1;
            return "same={$same} n={$n}";
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class Target { public int $n = 0; }');
    eval('class Owner { public ?Target $target = null; public ?\WeakReference $ref = null; }');
};

spawn(function() use ($boot) {
    $target = new Target();
    $target->n = 77;
    $o = new Owner();
    $o->target = $target;
    $o->ref = \WeakReference::create($target);
    echo $o->run($boot), "\n";
});
?>
--EXPECT--
same=yes n=77
