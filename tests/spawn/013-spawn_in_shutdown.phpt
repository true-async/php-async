--TEST--
Future: spawn() - spawn in shutdown handler should fail
--FILE--
<?php

use function Async\spawn;

register_shutdown_function(function() {
    echo "shutdown handler start\n";
    
    try {
        spawn(function() {
            echo "should execute\n";
        });
        echo "spawn succeeded\n";
    } catch (Async\AsyncException $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    
    echo "shutdown handler end\n";
});

echo "start\n";

spawn(function() {
    echo "normal spawn works\n";
});

echo "end\n";
?>
--EXPECT--
start
end
normal spawn works
shutdown handler start
spawn succeeded
shutdown handler end
should execute