--TEST--
awaitFirstSuccess() - all coroutines throw exceptions
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;

echo "start\n";

$coroutines = [
    spawn(function() {
        throw new RuntimeException("first error");
    }),
    spawn(function() {
        throw new RuntimeException("second error");
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
  NULL
  [1]=>
  array(2) {
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
    [1]=>
    object(RuntimeException)#%d (7) {
      ["message":protected]=>
      string(12) "second error"
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