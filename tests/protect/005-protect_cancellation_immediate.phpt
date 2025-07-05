--TEST--
Async\protect: cancellation applied immediately after protected block
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;

$coroutine = spawn(function() {
    echo "before protect\n";
    
    protect(function() {
        echo "in protect\n";
    });
    
    echo "after protect\n";
    
    // This should not be reached due to deferred cancellation
    echo "this should not print\n";
});

// Cancel the coroutine
$coroutine->cancel();

try {
    await($coroutine);
} catch (Exception $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

?>
--EXPECTF--
before protect
in protect
after protect
caught exception: %s