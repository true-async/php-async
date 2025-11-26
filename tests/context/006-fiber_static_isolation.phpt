--TEST--
Coroutine static variable isolation
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Function with static variable
function counter() {
    static $count = 0;
    return ++$count;
}

// Test 1: Static variables are isolated between coroutines
echo "=== Test 1: Static function isolation ===\n";

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

// Test 2: Global code should still work (backward compatibility)
echo "\n=== Test 2: Global code backward compatibility ===\n";

function global_counter() {
    static $value = 100;
    return ++$value;
}

echo "First call: " . global_counter() . "\n";  // Should be 101
echo "Second call: " . global_counter() . "\n"; // Should be 102

// Test 3: Multiple concurrent coroutines
echo "\n=== Test 3: Multiple concurrent coroutines ===\n";

function shared_increment() {
    static $shared = 0;
    return ++$shared;
}

$coroutines = [];
for ($i = 0; $i < 3; $i++) {
    $coroutines[] = spawn(function() {
        $res = [];
        for ($j = 0; $j < 3; $j++) {
            $res[] = shared_increment();
        }
        return $res;
    });
}

foreach ($coroutines as $idx => $coro) {
    $res = await($coro);
    echo "Coroutine $idx results: ";
    var_dump($res);
}

?>
--EXPECT--
=== Test 1: Static function isolation ===
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

=== Test 2: Global code backward compatibility ===
First call: 101
Second call: 102

=== Test 3: Multiple concurrent coroutines ===
Coroutine 0 results: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
Coroutine 1 results: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
Coroutine 2 results: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
