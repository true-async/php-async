--TEST--
pcntl_fork() throws when a coroutine other than the main one is running
--EXTENSIONS--
pcntl
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

// A live non-main coroutine makes fork() unsafe: its in-flight reactor state
// and worker threads cannot survive fork().
$c = spawn(function () { delay(10000); });

try {
    pcntl_fork();
    echo "FAIL: no exception\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}

$c->cancel();

?>
--EXPECT--
Cannot fork() while coroutines other than the main one are running. fork() is only allowed when just the main coroutine is active.
