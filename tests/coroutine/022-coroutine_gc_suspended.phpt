--TEST--
Coroutine: GC handler for suspended coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

// Test GC behavior with suspended coroutines
$coroutine = spawn(function() {
    // Suspend coroutine to test suspended state GC handling
    suspend();
    return "suspended_result";
});

// Force garbage collection while coroutine is suspended
$collected = gc_collect_cycles();

suspend(); // Suspend to simulate coroutine lifecycle
$result = $coroutine->getResult();
suspend(); // Ensure coroutine is resumed
$result = $coroutine->getResult();

var_dump($result);
var_dump($collected >= 0);

?>
--EXPECT--
string(16) "suspended_result"
bool(true)