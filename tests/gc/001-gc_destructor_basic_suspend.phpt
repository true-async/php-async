--TEST--
GC 001: Basic suspend in destructor
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

class TestObject {
    public $value;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        echo "Destructor start: {$this->value}\n";
        
        // Suspend in destructor - this is the key test scenario
        echo "Suspended in destructor: {$this->value}\n";
        suspend(); // No parameters - just yield control
        
        echo "Destructor end: {$this->value}\n";
    }
}

echo "Starting test\n";

// Create object that will be garbage collected
$obj = new TestObject("test-object");

// Remove reference so object becomes eligible for GC
unset($obj);

echo "After unset\n";

// Force garbage collection
gc_collect_cycles();

echo "After GC\n";

// Start a coroutine to continue execution after destructor suspend
spawn(function() {
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
Created: test-object
Destructor start: test-object
Suspended in destructor: test-object
Destructor end: test-object
After unset
After GC
Test complete