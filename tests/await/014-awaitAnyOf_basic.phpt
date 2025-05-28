--TEST--
awaitAnyOf() - basic usage with count parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOf;
use function Async\delay;

echo "start\n";

$coroutines = [
    spawn(function() {
        delay(50);
        return "first";
    }),
    spawn(function() {
        delay(20);
        return "second";
    }),
    spawn(function() {
        delay(30);
        return "third";
    }),
    spawn(function() {
        delay(25);
        return "fourth";
    }),
];

$results = awaitAnyOf(2, $coroutines);
var_dump($results);

echo "end\n";
?>
--EXPECT--
start
array(2) {
  [1]=>
  string(6) "second"
  [3]=>
  string(6) "fourth"
}
end