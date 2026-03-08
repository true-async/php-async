--TEST--
include inside coroutine - with return value
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    $result = include __DIR__ . '/test_include_returns.inc';

    var_dump($result);
});

await($coroutine);

echo "done\n";
?>
--EXPECT--
array(2) {
  ["key"]=>
  string(5) "value"
  ["number"]=>
  int(123)
}
done
