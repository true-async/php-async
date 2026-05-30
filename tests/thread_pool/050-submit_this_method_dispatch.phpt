--TEST--
ThreadPool: submit() $this->method() dispatch inside the transferred closure
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
    public int $n = 3;

    public function helper(): int {
        return $this->n * 10;
    }

    public function go(ThreadPool $pool): int {
        $f = $pool->submit(function() {
            return $this->helper();
        });
        return await($f);
    }
}

$boot = function() {
    eval('class C { public int $n = 3; function helper(): int { return $this->n * 10; } }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=30
