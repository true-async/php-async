--TEST--
GC 006: Simple destructor test without async operations
--FILE--
<?php

class TestObject {
    public $value;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        echo "Destructor: {$this->value}\n";
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

echo "Test complete\n";

?>
--EXPECT--
Starting test
Created: test-object
Destructor: test-object
After unset
Test complete