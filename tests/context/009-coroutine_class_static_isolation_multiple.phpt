--TEST--
Coroutine class static property isolation - multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Class with static property
class Counter {
    public static int $value = 0;

    public static function increment() {
        return ++self::$value;
    }
}

// Create 3 concurrent coroutines, each incrementing the counter
$coroutines = [];
for ($i = 0; $i < 3; $i++) {
    $coroutines[] = spawn(function() {
        $res = [];
        for ($j = 0; $j < 3; $j++) {
            $res[] = Counter::increment();
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

// Global scope should still have original value
echo "Global Counter::value: " . Counter::$value . "\n";

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
Global Counter::value: 0
