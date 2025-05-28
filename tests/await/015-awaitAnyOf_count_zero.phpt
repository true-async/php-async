--TEST--
awaitAnyOf() - count is zero
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOf;
use function Async\delay;

echo "start\n";

$coroutines = [
    spawn(function() {
        delay(20);
        return "first";
    }),
    spawn(function() {
        delay(30);
        return "second";
    }),
];

$results = awaitAnyOf(0, $coroutines);
var_dump($results);

echo "end\n";
?>
--EXPECT--
start
array(0) {
}
end