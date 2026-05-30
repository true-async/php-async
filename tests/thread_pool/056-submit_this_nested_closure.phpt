--TEST--
ThreadPool: submit() nested closure created at runtime inherits $this from outer
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
    public int $n = 14;

    public function go(ThreadPool $pool): int {
        $outer = function() {
            $inner = function() {
                return $this->n * 2;
            };
            return $inner();
        };
        return await($pool->submit(Closure::bind($outer, $this, C::class)));
    }
}

$boot = function() {
    eval('class C { public int $n = 14; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=28
