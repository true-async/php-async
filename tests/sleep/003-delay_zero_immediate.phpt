--TEST--
Async\delay(0): enqueues the current coroutine without a timer
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

// Covers async.c PHP_FUNCTION(Async_delay) L671-672 fast path:
// ms == 0 skips zend_async_waker_new_with_timeout and uses
// ZEND_ASYNC_ENQUEUE_COROUTINE directly.

$order = [];
$c1 = spawn(function() use (&$order) {
    $order[] = 'before';
    delay(0);
    $order[] = 'after';
});

await($c1);
print_r($order);

?>
--EXPECT--
Array
(
    [0] => before
    [1] => after
)
