--TEST--
awaitAllWithErrors() - all coroutines succeed
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllWithErrors;
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

$result = awaitAllWithErrors($coroutines);
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