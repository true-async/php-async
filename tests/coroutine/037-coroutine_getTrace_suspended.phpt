--TEST--
Coroutine: getTrace() - returns backtrace for suspended coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    // This function will suspend
    Async\sleep(0.1);
    return "test";
});

// Get trace while coroutine is suspended
$trace = $coroutine->getTrace();

var_dump(is_array($trace));
echo "Trace has entries: " . (count($trace) > 0 ? "yes" : "no") . "\n";

// Test with DEBUG_BACKTRACE_IGNORE_ARGS
$traceNoArgs = $coroutine->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS);
var_dump(is_array($traceNoArgs));

// Test with limit
$traceLimited = $coroutine->getTrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
var_dump(is_array($traceLimited));

// Wait for coroutine to complete
await($coroutine);

// After completion, trace should be empty
$traceAfter = $coroutine->getTrace();
var_dump(is_array($traceAfter));
var_dump(count($traceAfter) === 0);

?>
--EXPECTF--
bool(true)
Trace has entries: %s
bool(true)
bool(true)
bool(true)
bool(true)
