--TEST--
ThreadPool: submit() $this with a self-cycle ($this->self === $this)
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
    public $self;
    public int $n = 5;

    public function go(ThreadPool $pool): string {
        $this->self = $this;
        $f = $pool->submit(function() {
            return var_export($this->self === $this, true) . ":" . $this->n;
        });
        return await($f);
    }
}

$boot = function() {
    eval('class C { public $self; public int $n = 5; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
true:5
