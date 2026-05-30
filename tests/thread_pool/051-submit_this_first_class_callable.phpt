--TEST--
ThreadPool: submit() first-class callable from an instance method ($this->method(...))
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
    public int $n = 4;

    public function work(): string {
        return "n={$this->n} => " . ($this->n + 100);
    }

    public function go(ThreadPool $pool): string {
        return await($pool->submit($this->work(...)));
    }
}

$boot = function() {
    eval('class C { public int $n = 4; function work(): string { return "n={$this->n} => " . ($this->n + 100); } }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=n=4 => 104
