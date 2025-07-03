--TEST--
GC 005: Simple circular references with suspend in destructor
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
        echo "Suspended in destructor: {$this->value}\n";
        suspend();
        echo "Destructor end: {$this->value}\n";
    }
    
    public function setRef($ref) {
        $this->ref = $ref;
    }
}

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

// Force garbage collection
$collected = gc_collect_cycles();
echo "GC collected cycles: {$collected}\n";

echo "After GC\n";

// Continue execution
spawn(function() {
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
Created: object-A
Created: object-B
Created circular reference
After unset
Destructor start: object-A
Suspended in destructor: object-A
Destructor end: object-A
Destructor start: object-B
Suspended in destructor: object-B
Destructor end: object-B
GC collected cycles: 2
After GC
Test complete