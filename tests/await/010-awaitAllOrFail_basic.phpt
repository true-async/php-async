--TEST--
awaitAllOrFail() - basic usage with multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;

echo "start\n";

$coroutines = [
    spawn(function() {
        return "first";
    }),
    spawn(function() {
        return "second";
    }),
    spawn(function() {
        return "third";
    }),
];

$results = awaitAllOrFail($coroutines);
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