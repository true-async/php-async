--TEST--
Coroutine: getSuspendFileAndLine() and getSuspendLocation() - basic usage
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

$coroutine = spawn(function() {
    suspend(); // This should record suspend location
    return "test";
});

// Give coroutine a chance to start and suspend
// TODO: This test may need adjustment based on suspend behavior

$fileAndLine = $coroutine->getSuspendFileAndLine();
$location = $coroutine->getSuspendLocation();

var_dump(is_array($fileAndLine));
var_dump(count($fileAndLine) === 2);
var_dump(is_string($location));

?>
--EXPECT--
bool(true)
bool(true)
bool(true)