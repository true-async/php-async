--TEST--
GC 008: Object resurrection through suspended destructor
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

// Global to "resurrect" objects
$global_resurrection_storage = [];

class TestObject {
    public $value;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        global $global_resurrection_storage;
        
        echo "Destructor start: {$this->value}\n";
        
        // Suspend in destructor and "resurrect" the object by storing a reference
        echo "Suspended in destructor: {$this->value}\n";
        
        // "Resurrect" by storing reference to self
        echo "Resurrecting object: {$this->value}\n";
        $global_resurrection_storage[] = $this;
        
        suspend();
        
        echo "Destructor end: {$this->value}\n";
    }
    
    public function doSomething() {
        echo "Object {$this->value} is alive and working!\n";
    }
}

echo "Starting test\n";

// Create object that should be garbage collected
$obj = new TestObject("zombie-object");

// Remove local reference so object becomes eligible for GC
unset($obj);

echo "After unset\n";

// Force garbage collection - this should trigger destructor
gc_collect_cycles();

echo "After GC\n";

// Check if object was "resurrected"
echo "Checking resurrection storage...\n";
if (!empty($global_resurrection_storage)) {
    echo "Found resurrected objects: " . count($global_resurrection_storage) . "\n";
    
    foreach ($global_resurrection_storage as $resurrected) {
        echo "Resurrected object value: {$resurrected->value}\n";
        $resurrected->doSomething();
    }
} else {
    echo "No objects were resurrected\n";
}

// Force another GC to clean up resurrected objects
$global_resurrection_storage = [];
gc_collect_cycles();

// Continue execution
spawn(function() {
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
Created: zombie-object
Destructor start: zombie-object
Suspended in destructor: zombie-object
Resurrecting object: zombie-object
Destructor end: zombie-object
After unset
After GC
Checking resurrection storage...
Found resurrected objects: 1
Resurrected object value: zombie-object
Object zombie-object is alive and working!
Test complete