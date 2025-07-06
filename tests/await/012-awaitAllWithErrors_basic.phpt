--TEST--
awaitAllWithErrors() - basic usage with mixed success and error
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllWithErrors;

echo "start\n";

$coroutines = [
    spawn(function() {
        return "first";
    }),
    spawn(function() {
        throw new RuntimeException("test exception");
    }),
    spawn(function() {
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