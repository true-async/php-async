--TEST--
Coroutine static variable isolation - basic
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Function with static variable
function counter() {
    static $count = 0;
    return ++$count;
}

// Test: Static variables are isolated between coroutines
$coro1 = spawn(function() {
    $results = [];
    $results[] = counter(); // Should be 1
    $results[] = counter(); // Should be 2
    $results[] = counter(); // Should be 3
    return $results;
});

$coro2 = spawn(function() {
    $results = [];
    $results[] = counter(); // Should be 1 (isolated from coro1)
    $results[] = counter(); // Should be 2 (isolated from coro1)
    $results[] = counter(); // Should be 3 (isolated from coro1)
    return $results;
});

$results1 = await($coro1);
$results2 = await($coro2);

var_dump($results1);
var_dump($results2);

?>
--EXPECT--
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
