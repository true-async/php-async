--TEST--
Future: getCreatedFileAndLine() and getCreatedLocation()
--FILE--
<?php

use Async\FutureState;
use Async\Future;

$state = new FutureState(); $line = __LINE__;
$future = new Future($state);

$fileAndLine = $future->getCreatedFileAndLine();
$location = $future->getCreatedLocation();

// Future shares the underlying zend_future_t with FutureState
var_dump(is_array($fileAndLine));
var_dump(count($fileAndLine) === 2);
var_dump(str_contains($fileAndLine[0], basename(__FILE__)));
var_dump($fileAndLine[1] === $line);
var_dump(str_contains($location, basename(__FILE__)));

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
