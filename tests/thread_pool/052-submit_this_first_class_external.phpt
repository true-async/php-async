--TEST--
ThreadPool: submit() first-class callable from an external object ($obj->method(...))
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
    public int $n = 6;

    public function work(): int {
        return $this->n + 1;
    }
}

$boot = function() {
    eval('class C { public int $n = 6; function work(): int { return $this->n + 1; } }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    $c = new C();
    echo "result=", await($pool->submit($c->work(...))), "\n";
    $pool->close();
});
?>
--EXPECT--
result=7
