--TEST--
GC 004: Exception handling with suspend in destructor
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

class TestObject {
    public $value;
    public $should_throw;
    
    public function __construct($value, $should_throw = false) {
        $this->value = $value;
        $this->should_throw = $should_throw;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        echo "Destructor start: {$this->value}\n";
        
        try {
            // Suspend in destructor
            echo "Suspended in destructor: {$this->value}\n";
            suspend();
            
            if ($this->should_throw) {
                throw new Exception("Test exception after suspend");
            }
            
            echo "Destructor middle: {$this->value}\n";
            
        } catch (Exception $e) {
            echo "Exception caught in destructor: {$e->getMessage()}\n";
        }
        
        echo "Destructor end: {$this->value}\n";
    }
}

spawn(function() {
    echo "Starting test\n";
    
    // Test 1: Normal case without exception
    echo "=== Test 1: Normal case ===\n";
    $obj1 = new TestObject("normal", false);
    unset($obj1);
    gc_collect_cycles();
    
    suspend();
    
    // Test 2: Exception case
    echo "=== Test 2: Exception case ===\n";
    $obj2 = new TestObject("exception", true);
    unset($obj2);
    gc_collect_cycles();
    
    suspend();
    
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
=== Test 1: Normal case ===
Created: normal
Destructor start: normal
Suspended in destructor: normal
Destructor middle: normal
Destructor end: normal
=== Test 2: Exception case ===
Created: exception
Destructor start: exception
Suspended in destructor: exception
Exception caught in destructor: Test exception after suspend
Destructor end: exception
Test complete