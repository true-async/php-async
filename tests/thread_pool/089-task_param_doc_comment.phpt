--TEST--
ThreadPool: a parameter doc comment does not follow the task across threads
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

// The doc comment of a parameter is an ordinary string of the submitting
// thread, and the arg_info copy carried the pointer over untouched. The worker
// releases arg_info with the op_array, so it released a string it never owned.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    var_dump(await($pool->submit(function (/** @param int */ int $x = 7) { return $x; })));
    var_dump(await($pool->submit(function (/** @param int */ int $x = 8) { return $x; })));

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
int(7)
int(8)
Done
