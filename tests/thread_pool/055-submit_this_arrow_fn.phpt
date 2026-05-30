--TEST--
ThreadPool: submit() arrow function fn() => $this->x
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
    public int $n = 13;

    public function go(ThreadPool $pool): int {
        return await($pool->submit(fn() => $this->n + 7));
    }
}

$boot = function() {
    eval('class C { public int $n = 13; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=20
