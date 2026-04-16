--TEST--
ThreadPool: double close does not crash
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
    $pool = new ThreadPool(2);
    $pool->close();
    $pool->close();
    echo "isClosed: " . ($pool->isClosed() ? "yes" : "no") . "\n";
    echo "Done\n";
});
?>
--EXPECT--
isClosed: yes
Done
