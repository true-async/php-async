--TEST--
Coroutine class static property isolation - global code compatibility
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Class with static property used in global scope
class State {
    public static int $counter = 100;

    public static function increment() {
        return ++self::$counter;
    }
}

// Use in global code (non-coroutine context)
echo "Global scope:\n";
echo "First call: " . State::increment() . "\n";   // Should be 101
echo "Second call: " . State::increment() . "\n";  // Should be 102

// Use in coroutine (should be isolated)
echo "\nCoroutine scope:\n";
$coro = spawn(function() {
    $res = [];
    for ($i = 0; $i < 3; $i++) {
        $res[] = State::increment();
    }
    return $res;
});

$res = await($coro);
var_dump($res); // Should be [101, 102, 103] (isolated from global)

// Global scope continues with its own state
echo "\nGlobal scope again:\n";
echo "Third call: " . State::increment() . "\n";   // Should be 103 (continuing from 102)

?>
--EXPECT--
Global scope:
First call: 101
Second call: 102

Coroutine scope:
array(3) {
  [0]=>
  int(101)
  [1]=>
  int(102)
  [2]=>
  int(103)
}

Global scope again:
Third call: 103
