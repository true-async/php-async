--TEST--
ThreadPool: submit() $this with a WeakReference property; identity preserved when target reachable
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

class T {
    public int $v = 99;
}

class C {
    public \WeakReference $w;
    public T $strong;

    public function go(ThreadPool $pool) {
        $f = $pool->submit(function() {
            $t = $this->w->get();
            return $t === null ? "dead" : $t->v;
        });
        return await($f);
    }
}

$boot = function() {
    eval('class T { public int $v = 99; } class C { public \WeakReference $w; public T $strong; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    $c = new C();
    $t = new T();
    $c->strong = $t;
    $c->w = \WeakReference::create($t);
    echo "result=", $c->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=99
