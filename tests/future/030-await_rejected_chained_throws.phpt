--TEST--
Future::await() on rejected future via chained call throws exception without warning
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

function make_rejected_future(): Future {
    $state = new FutureState();
    $future = new Future($state);
    $state->error(new Exception("Test rejection"));
    return $future;
}

$coroutine = spawn(function() {
    // Chained call — future is a temporary, not stored in a variable
    try {
        $result = make_rejected_future()->await();
        echo "Should not reach here, got: " . var_export($result, true) . "\n";
    } catch (Exception $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Caught: Test rejection
Done
