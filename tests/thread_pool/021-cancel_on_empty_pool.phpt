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
    echo "workers=" . $pool->getWorkerCount() . "\n";
    echo "done\n";
});
?>
--EXPECT--
closed=yes
workers=4
done
