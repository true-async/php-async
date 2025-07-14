--TEST--
awaitAll() - The object used to cancel the wait is simultaneously the object being awaited.
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

$result = awaitAll([$coroutine1, $coroutine2], $coroutine2);
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