--TEST--
Coroutine $GLOBALS array isolation
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Create variables in main scope
$x = 100;
$y = 200;

$coro = spawn(function() {
    // Coroutine has isolated $GLOBALS - does NOT see $x, $y from main scope
    echo "isset(\$x) in coroutine: " . (isset($x) ? "yes" : "no") . "\n";
    echo "isset(\$GLOBALS['x']) in coroutine: " . (isset($GLOBALS['x']) ? "yes" : "no") . "\n";

    // Create new variables in coroutine's isolated $GLOBALS
    global $x, $y;
    $x = 500;
    $y = 600;

    echo "After setting x,y via global: x=" . $x . ", y=" . $y . "\n";
    echo "\$GLOBALS['x']=" . $GLOBALS['x'] . ", \$GLOBALS['y']=" . $GLOBALS['y'] . "\n";

    return [$x, $y];
});

$res = await($coro);
echo "Coroutine result: ";
var_dump($res);

// Verify coroutine's changes did NOT affect main scope
echo "x in main: " . $x . ", y in main: " . $y . "\n";

?>
--EXPECT--
isset($x) in coroutine: no
isset($GLOBALS['x']) in coroutine: no
After setting x,y via global: x=500, y=600
$GLOBALS['x']=500, $GLOBALS['y']=600
Coroutine result: array(2) {
  [0]=>
  int(500)
  [1]=>
  int(600)
}
x in main: 100, y in main: 200
