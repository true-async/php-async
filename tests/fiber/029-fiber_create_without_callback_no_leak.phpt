--TEST--
Fiber created without callback should not leak async scope
--FILE--
<?php
// Creating a Fiber without a callback triggers async_scheduler_launch()
// in zend_fiber_object_create(), which allocates ZEND_ASYNC_MAIN_SCOPE.
// The constructor then throws an exception because no callback was provided.
// The scope must still be properly freed on shutdown.
try {
    new Fiber;
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
echo "OK\n";
?>
--EXPECT--
Fiber::__construct() expects exactly 1 argument, 0 given
OK
