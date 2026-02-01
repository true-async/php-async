--TEST--
FutureState: getCreatedFileAndLine() and getCreatedLocation()
--FILE--
<?php

use Async\FutureState;

$state = new FutureState(); $line = __LINE__;

$fileAndLine = $state->getCreatedFileAndLine();
$location = $state->getCreatedLocation();

var_dump(is_array($fileAndLine));
var_dump(count($fileAndLine) === 2);
var_dump(str_contains($fileAndLine[0], basename(__FILE__)));
var_dump($fileAndLine[1] === $line);
var_dump(str_contains($location, basename(__FILE__)));
var_dump(str_contains($location, (string)$line));

$state->ignore();

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
