--TEST--
ThreadPool: cancel immediately after construction, no submits
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;

spawn(function() {
    $pool = new ThreadPool(4);
    $pool->cancel();
    echo "closed=" . ($pool->isClosed() ? "yes" : "no") . "\n";
    // The live worker count is not asserted here: cancel() has told the
    // threads to leave and does not wait for them, so the number depends on
    // how far they got. 082-worker_liveness.phpt covers what the count means.
    echo "done\n";
});
?>
--EXPECT--
closed=yes
done
