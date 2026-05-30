--TEST--
ThreadPool: submit() closure that is both $this-bound and captures use() vars
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
    public int $n = 10;

    public function go(ThreadPool $pool, int $extra): int {
        $f = $pool->submit(function() use ($extra) {
            return $this->n + $extra;
        });
        return await($f);
    }
}

$boot = function() {
    eval('class C { public int $n = 10; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", (new C)->go($pool, 5), "\n";
    $pool->close();
});
?>
--EXPECT--
result=15
