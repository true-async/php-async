--TEST--
GC 005: Circular references with suspend in destructor
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

class TestObject {
    public $value;
    public $ref = null;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        echo "Destructor start: {$this->value}\n";
        
        // Suspend in destructor - this is where the GC gets complex
        echo "Suspended in destructor: {$this->value}\n";
        
        // Check if we still have a reference during GC
        if ($this->ref !== null) {
            echo "Still has reference to: {$this->ref->value}\n";
        } else {
            echo "Reference is null during GC\n";
        }
        
        suspend();
        
        echo "Destructor end: {$this->value}\n";
    }
    
    public function setRef($ref) {
        $this->ref = $ref;
    }
}

spawn(function() {
    echo "Starting test\n";
    
    // Create circular reference
    $obj1 = new TestObject("object-A");
    $obj2 = new TestObject("object-B");
    
    // Create cycle: A -> B -> A
    $obj1->setRef($obj2);
    $obj2->setRef($obj1);
    
    echo "Created circular reference\n";
    
    // Remove references so objects become eligible for GC
    unset($obj1, $obj2);
    
    echo "After unset\n";
    
    // Force garbage collection - this should handle the cycle with suspended destructors
    $collected = gc_collect_cycles();
    echo "GC collected cycles: {$collected}\n";
    
    echo "After GC\n";
    
    // Small suspend to let any remaining async operations complete
    suspend();
    
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
Created: object-A
Created: object-B
Created circular reference
After unset
GC collected cycles: 0
After GC
Destructor start: object-B
Suspended in destructor: object-B
Still has reference to: object-A
Destructor start: object-A
Suspended in destructor: object-A
Still has reference to: object-B
Test complete
Destructor end: object-B
Destructor end: object-A