--TEST--
Multiple deferred cancellations with sequential protect blocks
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\protect;
use function Async\await;

echo "start\n";

$multi_protected = spawn(function() {
    echo "multi protected started\n";
    
    protect(function() {
        echo "first protected operation\n";
        suspend();
        echo "first protected completed\n";
    });
    
    echo "between protections\n";
    
    protect(function() {
        echo "second protected operation\n";
        suspend();
        echo "second protected completed\n";
    });
    
    echo "all protections completed\n";
    return "multi_result";
});

suspend(); // Enter first protection

$multi_protected->cancel(new \Async\CancellationException("Multi deferred"));
echo "multi cancelled during first protection\n";

try {
    await($multi_protected);
} catch (\Async\CancellationException $e) {
    echo "multi deferred cancellation: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
multi protected started
first protected operation
multi cancelled during first protection
first protected completed
multi deferred cancellation: Multi deferred
end