--TEST--
Coroutine static variable isolation - global code compatibility
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Function with static variable used in global scope
function global_counter() {
    static $value = 100;
    return ++$value;
}

// Use static function in global code (non-coroutine context)
echo "Global scope:\n";
echo "First call: " . global_counter() . "\n";   // Should be 101
echo "Second call: " . global_counter() . "\n";  // Should be 102

// Use same static function in coroutine (should be isolated)
echo "\nCoroutine scope:\n";
$coro = spawn(function() {
    $res = [];
    for ($i = 0; $i < 3; $i++) {
        $res[] = global_counter();
    }
    return $res;
});

$res = await($coro);
var_dump($res); // Should be [101, 102, 103] (isolated from global)

// Global scope continues with its own state
echo "\nGlobal scope again:\n";
echo "Third call: " . global_counter() . "\n";   // Should be 103 (continuing from 102)

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
