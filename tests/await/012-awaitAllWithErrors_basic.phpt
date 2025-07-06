--TEST--
awaitAllWithErrors() - basic usage with mixed success and error
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
        throw new RuntimeException("test exception");
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
--EXPECTF--
start
array(2) {
  [0]=>
  array(2) {
    [0]=>
    string(5) "first"
    [2]=>
    string(5) "third"
  }
  [1]=>
  array(1) {
    [1]=>
    object(RuntimeException)#%d (7) {
      ["message":protected]=>
      string(14) "test exception"
      ["string":"Exception":private]=>
      string(0) ""
      ["code":protected]=>
      int(0)
      ["file":protected]=>
      string(%d) "%s"
      ["line":protected]=>
      int(%d)
      ["trace":"Exception":private]=>
      array(%d) {
        %a
      }
      ["previous":"Exception":private]=>
      NULL
    }
  }
}
end