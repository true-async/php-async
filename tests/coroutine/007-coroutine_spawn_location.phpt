--TEST--
Coroutine: getSpawnFileAndLine() and getSpawnLocation() - basic usage
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

$fileAndLine = $coroutine->getSpawnFileAndLine();
$location = $coroutine->getSpawnLocation();

var_dump(is_array($fileAndLine));
var_dump(count($fileAndLine) === 2);
var_dump(is_string($fileAndLine[0]) || is_null($fileAndLine[0]));
var_dump(is_int($fileAndLine[1]));

var_dump(is_string($location));
echo "Location contains file info: " . (strpos($location, ':') !== false ? "yes" : "no") . "\n";

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
Location contains file info: yes