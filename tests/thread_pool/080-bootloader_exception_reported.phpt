--TEST--
ThreadPool: a bootloader that throws is reported through the worker's error stream with nothing submitted
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;

// Task rejection is the pool's other channel for a failed bootloader, and a
// pool driven as a set of long-lived workers (an HTTP server) never reads it:
// nothing is submitted here at all. The failure must still reach display_errors
// and error_log the way any uncaught exception does, or the pool dies mute.
spawn(function() {
    $boot = function() { throw new \RuntimeException("boot failed!"); };
    $pool = new ThreadPool(1, 0, $boot);

    // The failing worker closes the pool; bounded so a regression fails the
    // test on output rather than hanging until the harness timeout.
    for ($i = 0; $i < 200 && !$pool->isClosed(); $i++) {
        \Async\delay(10);
    }

    $pool->close();
});
?>
--EXPECTF--
Fatal error: Uncaught RuntimeException: boot failed! in %s:%d
Stack trace:
%A
  thrown in %s on line %d
