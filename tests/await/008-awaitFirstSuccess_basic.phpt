--TEST--
awaitFirstSuccess() - basic usage with mixed success and error
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\delay;

echo "start\n";

$coroutines = [
    spawn(function() {
        delay(10);
        throw new RuntimeException("first error");
    }),
    spawn(function() {
        delay(20);
        return "success";
    }),
    spawn(function() {
        delay(30);
        return "another success";
    }),
];

$result = awaitFirstSuccess($coroutines);
var_dump($result);

echo "end\n";
?>
--EXPECTF--
start
array(2) {
  [0]=>
  string(7) "success"
  [1]=>
  array(1) {
    [0]=>
    object(RuntimeException)#%d (7) {
      ["message":protected]=>
      string(11) "first error"
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