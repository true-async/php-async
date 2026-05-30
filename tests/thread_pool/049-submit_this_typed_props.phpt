--TEST--
ThreadPool: submit() $this with strict-typed properties; types preserved in worker copy
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
    public float $f = 1.5;
    public ?string $s = null;

    public function go(ThreadPool $pool): string {
        $f = $pool->submit(function() {
            return gettype($this->f) . ":" . var_export($this->s, true);
        });
        return await($f);
    }
}

$boot = function() {
    eval('class C { public float $f = 1.5; public ?string $s = null; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    $c = new C();
    $c->f = 2.5;
    $c->s = "set";
    echo "result=", $c->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=double:'set'
