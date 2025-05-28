--TEST--
awaitAll() - basic usage with multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
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
];

$results = awaitAll($coroutines);
var_dump($results);

echo "end\n";
?>
--EXPECT--
start
array(3) {
  [0]=>
  string(5) "first"
  [1]=>
  string(6) "second"
  [2]=>
  string(5) "third"
}
end