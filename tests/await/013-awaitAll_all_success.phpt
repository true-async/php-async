--TEST--
awaitAll() - all coroutines succeed
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

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

$result = awaitAll($coroutines);
var_dump($result);

echo "end\n";
?>
--EXPECT--
start
array(2) {
  [0]=>
  array(3) {
    [0]=>
    string(5) "first"
    [1]=>
    string(6) "second"
    [2]=>
    string(5) "third"
  }
  [1]=>
  array(0) {
  }
}
end