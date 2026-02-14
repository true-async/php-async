--TEST--
Basic coroutine deferred cancellation with protected operation
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\protect;
use function Async\await;

echo "start\n";

$protected_coroutine = spawn(function() {
    echo "protected coroutine started\n";
    
    $result = protect(function() {
        echo "inside protected operation\n";
        suspend();
        echo "protected operation completed\n";
        return "protected_result";
    });
    
    echo "after protected operation: $result\n";
    return $result;
});

// Let coroutine start and enter protected operation
suspend();

echo "cancelling protected coroutine\n";
$protected_coroutine->cancel(new \Async\AsyncCancellation("Deferred cancellation"));

echo "protected coroutine cancelled: " . ($protected_coroutine->isCancelled() ? "true" : "false") . "\n";
echo "cancellation requested: " . ($protected_coroutine->isCancellationRequested() ? "true" : "false") . "\n";

// Let protected operation complete
suspend();

echo "after protected completion - cancelled: " . ($protected_coroutine->isCancelled() ? "true" : "false") . "\n";

try {
    await($protected_coroutine);
} catch (\Async\AsyncCancellation $e) {
}

$result = $protected_coroutine->getResult();

echo "protected result: ";
var_dump($result);

echo "end\n";

?>
--EXPECTF--
start
protected coroutine started
inside protected operation
cancelling protected coroutine
protected coroutine cancelled: false
cancellation requested: true
protected operation completed
after protected completion - cancelled: true
protected result: NULL
end