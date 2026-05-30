--TEST--
ThreadPool: submit() first-class callable from a static method (no $this)
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
    public static function work(): int {
        return 21 * 2;
    }
}

$boot = function() {
    eval('class C { static function work(): int { return 21 * 2; } }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", await($pool->submit(C::work(...))), "\n";
    $pool->close();
});
?>
--EXPECT--
result=42
