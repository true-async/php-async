--TEST--
Future::catch() - basic error recovery
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $recovered = $future->catch(function($e) {
        echo "Caught: " . $e->getMessage() . "\n";
        return 42;
    });

    $state->error(new Exception("Test error"));

    return await($recovered);
});

echo "Result: " . await($coroutine) . "\n";

?>
--EXPECT--
Caught: Test error
Result: 42
