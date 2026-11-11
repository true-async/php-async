--TEST--
Memory exhaustion while the main coroutine and another coroutine wait leaves no coroutine queued
--SKIPIF--
<?php
$zend_mm_enabled = getenv("USE_ZEND_ALLOC");
if ($zend_mm_enabled === "0") {
    die("skip Zend MM disabled");
}
?>
--INI--
memory_limit=2M
--FILE--
<?php

/* The bailout unwinds every waiting coroutine. The main coroutine unwinds
 * into the shutdown, which must not cancel and queue coroutine A again: the
 * bailout unwinds A next, and the queued entry would outlive it ("1 deferred
 * coroutines were not executed"). */

use function Async\spawn;
use function Async\delay;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

spawn(function() {
    echo "Coroutine A waits\n";
    delay(10000);
    echo "Coroutine A should not reach\n";
});

spawn(function() {
    delay(10);
    echo "Coroutine B allocates\n";
    $data = str_repeat('x', 10000000);
    echo "Coroutine B should not reach\n";
});

echo "Main waits\n";
delay(5000);
echo "Main should not reach\n";

?>
--EXPECTF--
Main waits
Coroutine A waits
Coroutine B allocates

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
Shutdown function called
