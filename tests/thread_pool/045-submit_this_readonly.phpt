--TEST--
ThreadPool: submit() $this with a readonly property
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
    public function __construct(public readonly int $n = 11) {}

    public function go(ThreadPool $pool): int {
        $f = $pool->submit(function() {
            return $this->n;
        });
        return await($f);
    }
}

$boot = function() {
    eval('class C { public function __construct(public readonly int $n = 11) {} }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", (new C(99))->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=99
