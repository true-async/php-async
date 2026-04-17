--TEST--
ThreadPool: closures with try/catch, static vars and dynamic_func_defs transfer cleanly
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\await;

// Covers thread.c:399-449 — the "op_array already in xlat cache" branch
// for closures that touch many op_array internal fields (static_variables,
// arg_info with return type, live_range, try_catch_array, dynamic_func_defs).
// The same closure is sent twice so the second submit hits the cache path.

$work = function (int $n): int {
    static $counter = 0;
    $counter++;

    $list = [1, 2, 3];
    try {
        foreach ($list as $i) {
            if ($i === $n) {
                throw new \LogicException("matched $n");
            }
        }
    } catch (\LogicException $e) {
        $f = function () { return 42; };
        return $f() + $n;
    } finally {
        $list = null;
    }
    return $n + $counter;
};

$pool = new ThreadPool(2);

$f1 = $pool->submit($work, 2);
$f2 = $pool->submit($work, 5);
$f3 = $pool->submit($work, 9);

var_dump(await($f1));
var_dump(await($f2));
var_dump(await($f3));

$pool->close();

echo "end\n";

?>
--EXPECT--
int(44)
int(6)
int(10)
end
