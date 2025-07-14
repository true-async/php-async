--TEST--
awaitAll() - Attempt to wait for two identical objects.
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "start\n";

$coroutine1 = spawn(function() {
    return "first";
});

$coroutine2 = spawn(function() {
    return "second";
});

$result = awaitAll([$coroutine1, $coroutine2, $coroutine1, $coroutine2]);
var_dump($result);

echo "end\n";
?>
--EXPECTF--
start
array(2) {
  [0]=>
  array(4) {
    [0]=>
    string(5) "first"
    [1]=>
    string(6) "second"
    [2]=>
    string(5) "first"
    [3]=>
    string(6) "second"
  }
  [1]=>
  array(0) {
  }
}
end