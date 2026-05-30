--TEST--
ThreadPool: submit() Closure::fromCallable([$this, 'method'])
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
    public int $n = 8;

    public function work(): int {
        return $this->n * 3;
    }

    public function go(ThreadPool $pool): int {
        $cl = Closure::fromCallable([$this, 'work']);
        return await($pool->submit($cl));
    }
}

$boot = function() {
    eval('class C { public int $n = 8; function work(): int { return $this->n * 3; } }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=24
