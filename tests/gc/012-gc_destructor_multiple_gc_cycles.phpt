--TEST--
GC 006: Multiple GC cycles with suspended destructors
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

class TestObject {
    public $value;
    public $ref = null;
    static $destructor_count = 0;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        self::$destructor_count++;
        echo "Destructor start: {$this->value} (count: " . self::$destructor_count . ")\n";
        
        // Suspend in destructor
        echo "Suspended in destructor: {$this->value}\n";
        suspend();
        echo "Destructor end: {$this->value}\n";
    }
    
    public function setRef($ref) {
        $this->ref = $ref;
    }
}

echo "Starting test\n";

// First batch - create objects that will suspend in destructor
echo "=== Creating first batch ===\n";
$obj1 = new TestObject("batch1-A");
$obj2 = new TestObject("batch1-B");
$obj1->setRef($obj2);
$obj2->setRef($obj1);

unset($obj1, $obj2);

// Trigger first GC cycle
echo "=== First GC cycle ===\n";
gc_collect_cycles();

// Second batch
echo "=== Creating second batch ===\n";
$obj3 = new TestObject("batch2-A");
$obj4 = new TestObject("batch2-B");
$obj3->setRef($obj4);
$obj4->setRef($obj3);

unset($obj3, $obj4);

// Trigger second GC cycle
echo "=== Second GC cycle ===\n";
gc_collect_cycles();

// Third GC cycle to clean up anything remaining
echo "=== Third GC cycle ===\n";
gc_collect_cycles();

echo "Total destructors called: " . TestObject::$destructor_count . "\n";

// Continue execution
spawn(function() {
    echo "Test complete\n";
});

?>
--EXPECT--
Starting test
=== Creating first batch ===
Created: batch1-A
Created: batch1-B
=== First GC cycle ===
Destructor start: batch1-A (count: 1)
Suspended in destructor: batch1-A
Destructor end: batch1-A
Destructor start: batch1-B (count: 2)
Suspended in destructor: batch1-B
Destructor end: batch1-B
=== Creating second batch ===
Created: batch2-A
Created: batch2-B
=== Second GC cycle ===
=== Third GC cycle ===
Total destructors called: 2
Destructor start: batch2-A (count: 3)
Suspended in destructor: batch2-A
Destructor start: batch2-B (count: 4)
Suspended in destructor: batch2-B
Test complete
Destructor end: batch2-A
Destructor end: batch2-B