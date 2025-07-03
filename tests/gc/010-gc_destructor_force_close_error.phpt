--TEST--
GC 010: Errors when async operations in terminated coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

class TestObject {
    public $value;
    public $is_terminated;
    
    public function __construct($value, $is_terminated = false) {
        $this->value = $value;
        $this->is_terminated = $is_terminated;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        echo "Destructor start: {$this->value}\n";
        
        if ($this->is_terminated) {
            echo "Attempting async operation in terminated context\n";
            
            try {
                // This should fail in a terminated/force-closed context
                echo "This should not execute in terminated context\n";
                suspend();
                
                echo "ERROR: Suspend succeeded when it should have failed\n";
                
            } catch (Exception $e) {
                echo "Expected exception: {$e->getMessage()}\n";
            }
            
            try {
                // This should also fail in a terminated context
                $spawned = spawn(function() {
                    echo "This spawn should not execute in terminated context\n";
                    return "should-not-happen";
                });
                
                echo "ERROR: Spawn succeeded when it should have failed\n";
                
            } catch (Exception $e) {
                echo "Expected spawn exception: {$e->getMessage()}\n";
            }
            
        } else {
            // Normal async operation should work
            echo "Normal async operation\n";
            
            echo "Normal suspend working: {$this->value}\n";
            suspend();
        }
        
        echo "Destructor end: {$this->value}\n";
    }
}

// Simulate different execution contexts
echo "Starting test\n";

// Test 1: Normal context (should work)
echo "=== Test 1: Normal context ===\n";
$normal_obj = new TestObject("normal");
unset($normal_obj);
gc_collect_cycles();

// Test 2: Create object in a context that will be terminated
echo "=== Test 2: Terminated context ===\n";

$terminated_coroutine = spawn(function() {
    // Create object that will be destructed when this coroutine is cancelled
    $terminated_obj = new TestObject("terminated", true);
    
    // This coroutine will be cancelled, causing force-close behavior
    suspend(); // Will be interrupted
    
    return "never-reached";
});

// Cancel the coroutine
$terminated_coroutine->cancel();

// Try to await the cancelled coroutine
try {
    await($terminated_coroutine);
} catch (Exception $e) {
    echo "Coroutine cancelled: {$e->getMessage()}\n";
}

// Force GC to clean up objects from terminated context
gc_collect_cycles();

echo "Test complete\n";

?>
--EXPECT--
Starting test
=== Test 1: Normal context ===
Created: normal
Destructor start: normal
Normal async operation
Normal suspend working: normal
Destructor end: normal
=== Test 2: Terminated context ===