--TEST--
ThreadPool: invalid worker count throws
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;

try {
    new ThreadPool(0);
} catch (\ValueError $e) {
    echo "Zero: " . $e->getMessage() . "\n";
}

try {
    new ThreadPool(-1);
} catch (\ValueError $e) {
    echo "Negative: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECTF--
Zero: %s
Negative: %s
Done
