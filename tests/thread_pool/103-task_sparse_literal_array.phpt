--TEST--
ThreadPool: a task closure carrying a literal array with a gap in its keys
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php
/* [0 => 'a', 2 => 'b'] compiles to a packed hash whose slot 1 is UNDEF, so its
 * nNumUsed is one more than its element count. The op_array copy that hands a
 * closure to a worker rebuilds literal arrays by key and skips that slot; the
 * invariant it used to assert belongs to static_variables, where BIND_LEXICAL
 * reads a byte offset into arData, and not to a literal. */

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

spawn(function() {
    $pool = new ThreadPool(1);

    $future = $pool->submit(function () {
        $sparse  = [0 => 'a', 2 => 'b'];
        $nested  = [1 => [3 => 'x'], 4 => 'y'];
        $strkeys = ['k' => 1, 'l' => 2];

        return implode(',', $sparse)
            . '|' . $nested[1][3] . $nested[4]
            . '|' . array_sum($strkeys)
            . '|' . implode(',', array_keys($sparse));
    });

    echo await($future), "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
a,b|xy|3|0,2
Done
