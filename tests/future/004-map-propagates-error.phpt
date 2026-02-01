--TEST--
Future::map() - propagates exception from source
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $mapped = $future->map(function($value) {
        echo "This should not be called\n";
        return $value;
    });

    $state->error(new Exception("Source error"));

    try {
        await($mapped);
    } catch (Exception $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
});

await($coroutine);

?>
--EXPECT--
Caught: Source error
