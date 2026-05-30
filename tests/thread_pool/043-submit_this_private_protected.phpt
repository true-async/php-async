--TEST--
ThreadPool: submit() $this-bound closure can access protected/private properties
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
    private int $secret = 7;
    protected string $p = "prot";

    public function go(ThreadPool $pool): string {
        $f = $pool->submit(function() {
            return "secret={$this->secret} p={$this->p} => " . ($this->secret * 2);
        });
        return await($f);
    }
}

$boot = function() {
    eval('class C { private int $secret = 7; protected string $p = "prot"; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
secret=7 p=prot => 14
