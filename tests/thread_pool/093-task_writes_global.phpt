--TEST--
ThreadPool: a task writing into $GLOBALS leaves nothing pointing into its snapshot
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

// The key of a global written by a task used to be the literal from the task's
// own op_array, which lives in the snapshot arena and dies with the task. The
// worker's symbol table kept the pointer and released it again at shutdown:
// "zend_mm_heap corrupted", then a crash.
//
// Several short names are written because the crash needs the dangling key to
// land where shutdown walks: with one name it depends on which bucket that name
// hashes to. The values are asserted too — a dangling key also broke the lookup,
// so the reader used to see nothing where the writer had left a number.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    await($pool->submit(static function() {
        $GLOBALS['x'] = 1;
        $GLOBALS['y'] = 2;
        $GLOBALS['z'] = 3;
        return 'written';
    }));

    $seen = await($pool->submit(static function() {
        return ($GLOBALS['x'] ?? 'gone') . ($GLOBALS['y'] ?? 'gone') . ($GLOBALS['z'] ?? 'gone');
    }));

    var_dump($seen);

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
string(3) "123"
Done
