--TEST--
Future::await() on rejected future throws exception
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    // Reject the future with an exception
    $state->error(new Exception("Test rejection"));

    // await() on already rejected future should throw
    try {
        $result = $future->await();
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
