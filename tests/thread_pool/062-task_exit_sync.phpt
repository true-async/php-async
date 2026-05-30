--TEST--
ThreadPool: exit() in a submitted task terminates the worker cleanly via ThreadTransferException (no crash)
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

spawn(function() {
    $pool = new ThreadPool(1);
    try {
        await($pool->submit(function() { exit(5); }));
        echo "no exception\n";
    } catch (\Async\ThreadTransferException $e) {
        echo "caught ThreadTransferException\n";
        echo "message not empty: ", (strlen($e->getMessage()) > 0 ? "yes" : "no"), "\n";
    }
    $pool->close();
});
?>
--EXPECT--
caught ThreadTransferException
message not empty: yes
