--TEST--
GC 007: Complex async operations in destructor
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Global to track spawned coroutines
$global_coroutines = [];

class TestObject {
    public $value;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        global $global_coroutines;
        
        echo "Destructor start: {$this->value}\n";
        
        // Complex async orchestration: spawn + suspend + await
        
        // 1. Spawn a new coroutine
        $spawned = spawn(function() {
            echo "Spawned coroutine start\n";
            suspend();
            echo "Spawned coroutine end\n";
            return "spawned-result";
        });
        
        $global_coroutines[] = $spawned;
        
        // 2. Suspend current execution
        echo "Suspended in destructor: {$this->value}\n";
        suspend();
        
        // 3. Resume and wait for the spawned coroutine
        echo "Resuming in destructor: {$this->value}\n";
        $result = await($spawned);
        echo "Spawned result: {$result}\n";
        
        // 4. Spawn another coroutine but don't wait for it
        $background = spawn(function() {
            suspend();
            echo "Background coroutine complete\n";
            return "background-result";
        });
        
        $global_coroutines[] = $background;
        
        echo "Destructor end: {$this->value}\n";
    }
}

echo "Starting test\n";

// Create object that will be garbage collected
$obj = new TestObject("complex-object");

// Remove reference so object becomes eligible for GC
unset($obj);

echo "After unset\n";

// Force garbage collection
gc_collect_cycles();

echo "After GC\n";

// Wait for background coroutines to complete
foreach ($global_coroutines as $coro) {
    $result = await($coro);
    echo "Final result: {$result}\n";
}

echo "Test complete\n";

?>
--EXPECT--
Starting test
Created: complex-object
Destructor start: complex-object
Suspended in destructor: complex-object
Spawned coroutine start
Resuming in destructor: complex-object
Spawned coroutine end
Spawned result: spawned-result
Destructor end: complex-object
After unset
After GC
Final result: spawned-result
Background coroutine complete
Final result: background-result
Test complete