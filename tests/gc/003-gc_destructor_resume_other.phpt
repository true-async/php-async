--TEST--
GC 003: Resume other coroutine from destructor
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

// Global variable to store suspended coroutine
$suspended_coroutine = null;

class TestObject {
    public $value;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        global $suspended_coroutine;
        
        echo "Destructor start: {$this->value}\n";
        
        // Resume the previously suspended coroutine
        if ($suspended_coroutine !== null) {
            echo "Resuming other coroutine from destructor\n";
            $result = await($suspended_coroutine);
            echo "Other coroutine result: {$result}\n";
        }
        
        echo "Destructor end: {$this->value}\n";
    }
}

echo "Starting test\n";

// Start a coroutine that will be suspended
$suspended_coroutine = spawn(function() {
    echo "Other coroutine start\n";
    suspend(); // Simulate async work
    echo "Other coroutine end\n";
    return "other-result";
});

// Create object that will be garbage collected
$obj = new TestObject("test-object");

// Remove reference so object becomes eligible for GC
unset($obj);

echo "After unset\n";

// Force garbage collection - this will trigger destructor
gc_collect_cycles();

echo "After GC\n";

// Continue execution
spawn(function() {
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
Created: test-object
Destructor start: test-object
Resuming other coroutine from destructor
Other coroutine start
Other coroutine end
Other coroutine result: other-result
Destructor end: test-object
After unset
After GC
Test complete