--TEST--
Coroutine global variable - creating new globals in coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Create coroutine that creates its own global variables
$coro = spawn(function() {
    global $new_var, $another_var;

    $new_var = 42;
    $another_var = "hello";

    return [$new_var, $another_var, isset($GLOBALS['new_var']), isset($GLOBALS['another_var'])];
});

$res = await($coro);
echo "Coroutine result: ";
var_dump($res);

// These globals should NOT exist in the main scope
echo "new_var exists in global: " . (isset($new_var) ? "yes" : "no") . "\n";
echo "another_var exists in global: " . (isset($another_var) ? "yes" : "no") . "\n";
echo "new_var in \$GLOBALS: " . (isset($GLOBALS['new_var']) ? "yes" : "no") . "\n";

?>
--EXPECT--
Coroutine result: array(4) {
  [0]=>
  int(42)
  [1]=>
  string(5) "hello"
  [2]=>
  bool(true)
  [3]=>
  bool(true)
}
new_var exists in global: no
another_var exists in global: no
new_var in $GLOBALS: no
