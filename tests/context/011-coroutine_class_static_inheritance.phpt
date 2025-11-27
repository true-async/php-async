--TEST--
Coroutine class static property inheritance - child and parent linked
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Parent class with static property
class BaseClass {
    public static int $value = 0;
}

// Child class inheriting static property
class ChildClass extends BaseClass {
    public static int $own = 100;
}

// Test inheritance in global scope
echo "Global scope:\n";
echo "BaseClass::value = " . BaseClass::$value . "\n";
echo "ChildClass::value = " . ChildClass::$value . "\n";
BaseClass::$value = 10;
echo "After BaseClass::value = 10:\n";
echo "BaseClass::value = " . BaseClass::$value . "\n";
echo "ChildClass::value = " . ChildClass::$value . "\n";  // Should also be 10 (same property)

// Test inheritance in coroutine (should be isolated but linked within coroutine)
echo "\nCoroutine scope:\n";
$coro = spawn(function() {
    echo "Initial - BaseClass::value = " . BaseClass::$value . "\n";
    echo "Initial - ChildClass::value = " . ChildClass::$value . "\n";

    BaseClass::$value = 20;
    echo "After BaseClass::value = 20:\n";
    echo "BaseClass::value = " . BaseClass::$value . "\n";
    echo "ChildClass::value = " . ChildClass::$value . "\n";  // Should also be 20 (linked)

    ChildClass::$own = 200;
    echo "ChildClass::own = " . ChildClass::$own . "\n";

    return [BaseClass::$value, ChildClass::$value, ChildClass::$own];
});

$res = await($coro);
var_dump($res);

// Global scope should be unchanged
echo "\nGlobal scope again:\n";
echo "BaseClass::value = " . BaseClass::$value . "\n";  // Should still be 10
echo "ChildClass::value = " . ChildClass::$value . "\n";    // Should still be 10
echo "ChildClass::own = " . ChildClass::$own . "\n";        // Should still be 100

?>
--EXPECT--
Global scope:
BaseClass::value = 0
ChildClass::value = 0
After BaseClass::value = 10:
BaseClass::value = 10
ChildClass::value = 10

Coroutine scope:
Initial - BaseClass::value = 0
Initial - ChildClass::value = 0
After BaseClass::value = 20:
BaseClass::value = 20
ChildClass::value = 20
ChildClass::own = 200
array(3) {
  [0]=>
  int(20)
  [1]=>
  int(20)
  [2]=>
  int(200)
}

Global scope again:
BaseClass::value = 10
ChildClass::value = 10
ChildClass::own = 100
