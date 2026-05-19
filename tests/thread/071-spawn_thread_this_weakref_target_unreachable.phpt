--TEST--
spawn_thread() - $this with WeakReference whose target is unreachable → dead WR in worker
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
    public ?\WeakReference $ref = null;
    public function run(\Closure $boot): string {
        $t = spawn_thread(function(): string {
            return ($this->ref?->get() === null) ? 'dead' : 'alive';
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class Target { public int $n = 0; }');
    eval('class Owner { public ?\WeakReference $ref = null; }');
};

spawn(function() use ($boot) {
    $target = new Target();
    $o = new Owner();
    $o->ref = \WeakReference::create($target);
    /* target is not reachable from $this — worker has no strong holder,
     * so the WR must observe a dead referent. */
    echo $o->run($boot), "\n";
});
?>
--EXPECT--
dead
