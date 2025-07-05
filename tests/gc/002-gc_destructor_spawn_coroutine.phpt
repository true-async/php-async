--TEST--
GC 002: Spawn new coroutine in destructor
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

class TestObject {
    public $value;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        echo "Destructor start: {$this->value}\n";
        
        // Spawn new coroutine in destructor - this is the key test scenario
        spawn(function() {
            echo "Spawned coroutine running\n";
            suspend();
            echo "Spawned coroutine complete\n";
        });
        
        echo "Coroutine spawned in destructor: {$this->value}\n";
        
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

// Continue execution to let spawned coroutines complete
spawn(function() {
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
Created: test-object
Destructor start: test-object
Coroutine spawned in destructor: test-object
Destructor end: test-object
After unset
After GC
Spawned coroutine running
Test complete
Spawned coroutine complete