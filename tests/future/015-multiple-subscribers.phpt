--TEST--
Future - multiple map subscribers on same future
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $map1 = $future->map(function($x) {
        echo "Map1: $x\n";
        return $x * 2;
    });

    $map2 = $future->map(function($x) {
        echo "Map2: $x\n";
        return $x * 3;
    });

    $state->complete(10);

    echo "Map1 result: " . await($map1) . "\n";
    echo "Map2 result: " . await($map2) . "\n";
});

await($coroutine);

?>
--EXPECT--
Map1: 10
Map2: 10
Map1 result: 20
Map2 result: 30
