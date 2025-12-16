--TEST--
Coroutine: getTrace() - returns empty array for non-suspended coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    return "test";
});

// Wait for coroutine to complete
await($coroutine);

// After completion, trace should be empty
$trace = $coroutine->getTrace();

var_dump(is_array($trace));
var_dump(count($trace));

// Test with options parameter
$trace2 = $coroutine->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS);
var_dump(is_array($trace2));
var_dump(count($trace2));

// Test with limit parameter
$trace3 = $coroutine->getTrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 5);
var_dump(is_array($trace3));
var_dump(count($trace3));

?>
--EXPECT--
bool(true)
int(0)
bool(true)
int(0)
bool(true)
int(0)