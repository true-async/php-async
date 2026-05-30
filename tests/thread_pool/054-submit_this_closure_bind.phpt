--TEST--
ThreadPool: submit() Closure::bind to an object before submit
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

class C {
    public int $n = 12;
}

$boot = function() {
    eval('class C { public int $n = 12; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    $c = new C();
    $cl = Closure::bind(function() {
        return $this->n + 5;
    }, $c, C::class);
    echo "result=", await($pool->submit($cl)), "\n";
    $pool->close();
});
?>
--EXPECT--
result=17
