--TEST--
ThreadPool: submit() a $this-bound closure whose object had its property table materialized
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
    public string $tag = "c";

    public function go(ThreadPool $pool, int $extra): string {
        // var_dump materializes $this's property table before the closure
        // (carrying $this) is transferred to the worker.
        ob_start(); var_dump($this); ob_end_clean();
        $f = $pool->submit(function() use ($extra) {
            return "{$this->tag}:" . ($this->n + $extra);
        });
        return await($f);
    }
}

$boot = function() {
    eval('class C { public int $n = 10; public string $tag = "c"; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    $obj = new C();
    $obj->n = 100;
    $obj->tag = "box";
    echo "result=", $obj->go($pool, 5), "\n";
    $pool->close();
});
?>
--EXPECT--
result=box:105
