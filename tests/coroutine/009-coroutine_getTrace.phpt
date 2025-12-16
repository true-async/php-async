--TEST--
Coroutine: getTrace() - returns null for non-suspended coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    return "test";
});

// Before the coroutine starts, trace should be null
$traceBeforeStart = $coroutine->getTrace();
echo "Trace before start is null: " . ($traceBeforeStart === null ? "yes" : "no") . "\n";

// Wait for coroutine to complete
await($coroutine);

// After completion, trace should be null
$trace = $coroutine->getTrace();
echo "Trace after completion is null: " . ($trace === null ? "yes" : "no") . "\n";

// Test with options parameter - should still return null
$trace2 = $coroutine->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS);
echo "Trace with IGNORE_ARGS is null: " . ($trace2 === null ? "yes" : "no") . "\n";

// Test with limit parameter - should still return null
$trace3 = $coroutine->getTrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 5);
echo "Trace with limit is null: " . ($trace3 === null ? "yes" : "no") . "\n";

?>
--EXPECT--
Trace before start is null: yes
Trace after completion is null: yes
Trace with IGNORE_ARGS is null: yes
Trace with limit is null: yes