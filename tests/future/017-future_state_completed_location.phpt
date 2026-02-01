--TEST--
FutureState: getCompletedFileAndLine() and getCompletedLocation()
--FILE--
<?php

use Async\FutureState;

$state = new FutureState();

// Before completion
$fileAndLine = $state->getCompletedFileAndLine();
$location = $state->getCompletedLocation();

var_dump($fileAndLine[0] === null);
var_dump($fileAndLine[1] === 0);
var_dump($location === 'unknown');

// After completion
$state->complete("done"); $line = __LINE__;

$fileAndLine = $state->getCompletedFileAndLine();
$location = $state->getCompletedLocation();

var_dump(str_contains($fileAndLine[0], basename(__FILE__)));
var_dump($fileAndLine[1] === $line);
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
