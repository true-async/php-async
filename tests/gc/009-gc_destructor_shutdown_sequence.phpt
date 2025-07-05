--TEST--
GC 009: Async operations in destructor during shutdown
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

class TestObject {
    public $value;
    
    public function __construct($value) {
        $this->value = $value;
        echo "Created: {$this->value}\n";
    }
    
    public function __destruct() {
        echo "Destructor start: {$this->value}\n";
        
        try {
            // Try to suspend during shutdown - this tests shutdown behavior
            echo "Suspended in destructor during shutdown: {$this->value}\n";
            
            // Try to spawn another coroutine during shutdown
            try {
                $spawned = spawn(function() {
                    echo "Spawned during shutdown\n";
                    return "shutdown-spawn-result";
                });
                
                $result = await($spawned);
                echo "Shutdown spawn result: {$result}\n";
            } catch (Exception $e) {
                echo "Exception during shutdown spawn: {$e->getMessage()}\n";
            }
            
            suspend();
            echo "Suspend completed during shutdown: {$this->value}\n";
            
        } catch (Exception $e) {
            echo "Exception in destructor during shutdown: {$e->getMessage()}\n";
        }
        
        echo "Destructor end: {$this->value}\n";
    }
}

// Register shutdown function to create objects during shutdown
register_shutdown_function(function() {
    echo "=== Shutdown function start ===\n";
    
    // Create object during shutdown - its destructor will run during shutdown
    $shutdown_obj = new TestObject("shutdown-object");
    unset($shutdown_obj); // This will trigger destructor during shutdown
    
    echo "=== Shutdown function end ===\n";
});

echo "Starting test\n";

// Create object that will be cleaned up normally (before shutdown)
$normal_obj = new TestObject("normal-object");
unset($normal_obj);
gc_collect_cycles();

echo "Normal cleanup complete\n";

// Continue execution
spawn(function() {
    echo "Main test complete\n";
    
    // Script ends here, shutdown functions will run
});

?>
--EXPECT--
Starting test
Created: normal-object
Destructor start: normal-object
Suspended in destructor during shutdown: normal-object
Spawned during shutdown
Shutdown spawn result: shutdown-spawn-result
Suspend completed during shutdown: normal-object
Destructor end: normal-object
Normal cleanup complete
Main test complete
=== Shutdown function start ===
Created: shutdown-object
Destructor start: shutdown-object
Suspended in destructor during shutdown: shutdown-object
Spawned during shutdown
Shutdown spawn result: shutdown-spawn-result
Suspend completed during shutdown: shutdown-object
Destructor end: shutdown-object
=== Shutdown function end ===