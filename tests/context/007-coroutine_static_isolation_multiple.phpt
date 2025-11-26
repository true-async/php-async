--TEST--
Coroutine static variable isolation - multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Function with static variable
function shared_counter() {
    static $counter = 0;
    return ++$counter;
}

// Create 3 concurrent coroutines, each calling shared_counter
$coroutines = [];
for ($i = 0; $i < 3; $i++) {
    $coroutines[] = spawn(function() {
        $res = [];
        for ($j = 0; $j < 3; $j++) {
            $res[] = shared_counter();
        }
        return $res;
    });
}

// Await all coroutines - each should have isolated counter
foreach ($coroutines as $idx => $coro) {
    $res = await($coro);
    echo "Coroutine $idx: ";
    var_dump($res);
}

?>
--EXPECT--
Coroutine 0: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
Coroutine 1: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
Coroutine 2: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
