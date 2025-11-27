--TEST--
Coroutine global variable isolation - multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Global variable created before coroutines
$global_counter = 0;

// Create 3 concurrent coroutines, each incrementing the global counter
$coroutines = [];
for ($i = 0; $i < 3; $i++) {
    $coroutines[] = spawn(function() {
        global $global_counter;

        // Each coroutine starts with 0 (isolated from global)
        $global_counter = 100;
        $global_counter++;

        $res = [];
        for ($j = 0; $j < 2; $j++) {
            $global_counter++;
            $res[] = $global_counter;
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

// Global scope should still have original value (unmodified)
echo "Global counter: " . $global_counter . "\n";

?>
--EXPECT--
Coroutine 0: array(2) {
  [0]=>
  int(102)
  [1]=>
  int(103)
}
Coroutine 1: array(2) {
  [0]=>
  int(102)
  [1]=>
  int(103)
}
Coroutine 2: array(2) {
  [0]=>
  int(102)
  [1]=>
  int(103)
}
Global counter: 0
