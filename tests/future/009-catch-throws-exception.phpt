--TEST--
Future::catch() - callback throws new exception
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $caught = $future->catch(function($e) {
        echo "First error: " . $e->getMessage() . "\n";
        throw new Exception("Second error");
    });

    $state->error(new Exception("First error"));

    try {
        await($caught);
    } catch (Exception $e) {
        echo "Final error: " . $e->getMessage() . "\n";
    }
});

await($coroutine);

?>
--EXPECT--
First error: First error
Final error: Second error
