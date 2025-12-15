--TEST--
Fiber and spawn operations in destructors - memory management conflicts
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "Test: Fiber and spawn in destructors\n";

class FiberSpawner {
    private $name;
    
    public function __construct($name) {
        $this->name = $name;
        echo "Created: {$this->name}\n";
    }
    
    public function __destruct() {
        echo "Destructing: {$this->name}\n";
        
        try {
            if ($this->name === 'FiberInDestructor') {
                // Create Fiber in destructor
                $fiber = new Fiber(function() {
                    echo "Fiber running in destructor\n";
                    Fiber::suspend("destructor fiber");
                    echo "Fiber resumed in destructor\n";
                    return "destructor done";
                });
                
                echo "Starting fiber in destructor\n";
                $result = $fiber->start();
                echo "Fiber suspended with: " . $result . "\n";
                
                $result = $fiber->resume("resume in destructor");
                echo "Fiber completed with: " . $result . "\n";
                
            } elseif ($this->name === 'SpawnInDestructor') {
                // Spawn coroutine in destructor
                echo "Spawning coroutine in destructor\n";
                $coroutine = spawn(function() {
                    echo "Coroutine running in destructor\n";
                    suspend();
                    echo "Coroutine resumed in destructor\n";
                    return "destructor coroutine done";
                });
                
                echo "Waiting for coroutine in destructor\n";
                $result = await($coroutine);
                echo "Coroutine completed with: " . $result . "\n";
            }
        } catch (Error $e) {
            echo "Error in destructor: " . $e->getMessage() . "\n";
        } catch (Exception $e) {
            echo "Exception in destructor: " . $e->getMessage() . "\n";
        }
        
        echo "Destructor finished: {$this->name}\n";
    }
}

try {
    echo "Creating objects that will spawn/fiber in destructors\n";
    
    $obj1 = new FiberSpawner('FiberInDestructor');
    $obj2 = new FiberSpawner('SpawnInDestructor'); 
    
    echo "Starting some async operations\n";
    $mainCoroutine = spawn(function() {
        echo "Main coroutine running\n";
        suspend();
        echo "Main coroutine resumed\n";
        return "main done";
    });
    
    // Force destruction by unsetting
    echo "Unsetting objects to trigger destructors\n";
    unset($obj1);
    unset($obj2);
    
    echo "Completing main coroutine\n";
    $result = await($mainCoroutine);
    echo "Main coroutine result: " . $result . "\n";
    
} catch (Error $e) {
    echo "Error caught: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Exception caught: " . $e->getMessage() . "\n";
}

echo "Test completed\n";
?>
--EXPECTF--
Test: Fiber and spawn in destructors
Creating objects that will spawn/fiber in destructors
Created: FiberInDestructor
Created: SpawnInDestructor
Starting some async operations
Unsetting objects to trigger destructors
Destructing: FiberInDestructor
Starting fiber in destructor
Main coroutine running
Fiber running in destructor
Main coroutine resumed
Fiber suspended with: destructor fiber
Fiber resumed in destructor
Fiber completed with: destructor done
Destructor finished: FiberInDestructor
Destructing: SpawnInDestructor
Spawning coroutine in destructor
Waiting for coroutine in destructor
Coroutine running in destructor
Coroutine resumed in destructor
Coroutine completed with: destructor coroutine done
Destructor finished: SpawnInDestructor
Completing main coroutine
Main coroutine result: main done
Test completed