--TEST--
Future: getCompletedFileAndLine() and getCompletedLocation()
--FILE--
<?php

use Async\FutureState;
use Async\Future;

$state = new FutureState();
$future = new Future($state);

// Before completion
$fileAndLine = $future->getCompletedFileAndLine();
$location = $future->getCompletedLocation();

var_dump($fileAndLine[0] === null);
var_dump($fileAndLine[1] === 0);
var_dump($location === 'unknown');

// After completion
$state->complete("done"); $line = __LINE__;

$fileAndLine = $future->getCompletedFileAndLine();
$location = $future->getCompletedLocation();

var_dump(str_contains($fileAndLine[0], basename(__FILE__)));
var_dump($fileAndLine[1] === $line);
var_dump(str_contains($location, (string)$line));

$future->ignore();

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
