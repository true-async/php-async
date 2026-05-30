--TEST--
ThreadPool: submit() $this-bound closure transfers $this as a deep copy
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

class Runner {
    public int $n = 0;
    public string $tag = "hi";

    public function go(ThreadPool $pool): string {
        // Return worker state instead of echoing from the worker thread:
        // cross-thread stdout ordering relative to the main coroutine is not deterministic.
        $f = $pool->submit(function() {
            $before = "n={$this->n} tag={$this->tag} class=" . get_class($this);
            $this->n = 999;
            return $before . " -> n={$this->n}";
        });
        return await($f);
    }
}

$boot = function() {
    eval('class Runner { public int $n = 0; public string $tag = "hi"; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    $r = new Runner();
    $r->n = 42;
    echo $r->go($pool), "\n";
    echo "parent n={$r->n}\n";
    $pool->close();
});
?>
--EXPECT--
n=42 tag=hi class=Runner -> n=999
parent n=42
