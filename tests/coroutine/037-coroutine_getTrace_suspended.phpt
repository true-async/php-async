--TEST--
Coroutine: getTrace() - returns backtrace for suspended coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

$parentCoroutine = null;
$childCoroutine = null;

// Spawn a parent coroutine
$parentCoroutine = spawn(function() use (&$childCoroutine) {
    echo "Parent: Starting\n";
    
    // Spawn a child coroutine that will suspend
    $childCoroutine = spawn(function() {
        echo "Child: Before suspend\n";
        suspend();
        echo "Child: After suspend\n";
    });
    
    // Suspend to let child start and suspend
    suspend();
    
    echo "Parent: Back from suspend\n";
    
    // Now check child's trace while it's suspended
    $trace = $childCoroutine->getTrace();
    
    if ($trace !== null) {
        echo "Child trace is array: " . (is_array($trace) ? "yes" : "no") . "\n";
        echo "Child trace has entries: " . (count($trace) > 0 ? "yes" : "no") . "\n";
        
        // Test with DEBUG_BACKTRACE_IGNORE_ARGS
        $traceNoArgs = $childCoroutine->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        echo "Trace with IGNORE_ARGS is array: " . (is_array($traceNoArgs) ? "yes" : "no") . "\n";
        
        // Test with limit
        $traceLimited = $childCoroutine->getTrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
        echo "Trace with limit is array: " . (is_array($traceLimited) ? "yes" : "no") . "\n";
    } else {
        echo "Child trace is null\n";
    }
    
    // Resume to let child finish
    suspend();
    
    // After completion, trace should be null
    $traceAfter = $childCoroutine->getTrace();
    echo "Child trace after completion is null: " . ($traceAfter === null ? "yes" : "no") . "\n";
});

?>
--EXPECT--
Parent: Starting
Child: Before suspend
Parent: Back from suspend
Child trace is array: yes
Child trace has entries: yes
Trace with IGNORE_ARGS is array: yes
Trace with limit is array: yes
Child: After suspend
Child trace after completion is null: yes
