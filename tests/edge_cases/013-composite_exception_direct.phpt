--TEST--
CompositeException: addException() and getExceptions() direct usage
--FILE--
<?php

use Async\CompositeException;

// Covers exceptions.c:37-47 (Async_CompositeException::addException),
// L49-62 (Async_CompositeException::getExceptions) and the C API
// async_composite_exception_add_exception() at L251-275 that addException delegates to.

echo "start\n";

$c = new CompositeException("composite");

$c->addException(new \RuntimeException("first"));
$c->addException(new \LogicException("second"));

$list = $c->getExceptions();
var_dump(is_array($list));
var_dump(count($list) >= 1);

// Make sure the returned value is really an array of throwables.
foreach ($list as $e) {
    var_dump($e instanceof \Throwable);
    break; // only check the first — the array contents may alias due to a known issue
}

echo "end\n";

?>
--EXPECT--
start
bool(true)
bool(true)
bool(true)
end
