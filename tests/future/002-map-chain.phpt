--TEST--
Future::map() - chaining multiple transformations
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $result = $future
        ->map(function($x) {
            echo "Map1: $x\n";
            return $x * 2;
        })
        ->map(function($x) {
            echo "Map2: $x\n";
            return $x + 10;
        })
        ->map(function($x) {
            echo "Map3: $x\n";
            return $x / 2;
        });

    $state->complete(5);

    return await($result);
});

echo "Final: " . await($coroutine) . "\n";

?>
--EXPECT--
Map1: 5
Map2: 10
Map3: 20
Final: 10
