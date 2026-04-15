--TEST--
CompositeException: addException() and getExceptions() direct usage
--FILE--
<?php

use Async\CompositeException;

// Covers exceptions.c Async_CompositeException::addException / getExceptions
// and the shared async_composite_exception_add_exception() helper. Exercises
// the fresh-array init path, multi-add append path, and empty-read path.

echo "start\n";

$c = new CompositeException("composite");

// Reading exceptions on a fresh composite must return [] (not a fatal).
$empty = $c->getExceptions();
var_dump($empty);

$c->addException(new \RuntimeException("first"));
$c->addException(new \LogicException("second"));
$c->addException(new \Exception("third"));

$list = $c->getExceptions();
var_dump(count($list));

foreach ($list as $i => $e) {
    echo $i, ": ", get_class($e), " - ", $e->getMessage(), "\n";
}

echo "end\n";

?>
--EXPECT--
start
array(0) {
}
int(3)
0: RuntimeException - first
1: LogicException - second
2: Exception - third
end
