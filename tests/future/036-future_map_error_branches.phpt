--TEST--
Future: map()/catch()/finally() TypeError on non-callable argument
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

// Covers future.c async_future_create_mapper() at L1647-1650:
// non-callable argument → zend_argument_type_error().

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    foreach (['map', 'catch', 'finally'] as $method) {
        try {
            $future->$method(42);
            echo "no-throw: $method\n";
        } catch (\TypeError $e) {
            echo "$method: TypeError\n";
        }
    }

    $state->complete(0);
    $future->ignore();
});

await($coroutine);

?>
--EXPECT--
map: TypeError
catch: TypeError
finally: TypeError
