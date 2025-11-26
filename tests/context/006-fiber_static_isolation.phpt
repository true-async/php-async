--TEST--
Fiber static variable isolation
--FILE--
<?php

use Async\{Task, Fiber, Scheduler};

// Function with static variable
function counter() {
    static $count = 0;
    return ++$count;
}

// Test 1: Static variables are isolated between fibers
echo "=== Test 1: Static function isolation ===\n";

$fiber1_results = [];
$fiber2_results = [];

async function fiber1() use (&$fiber1_results) {
    $fiber1_results[] = counter(); // Should be 1
    $fiber1_results[] = counter(); // Should be 2
    $fiber1_results[] = counter(); // Should be 3
}

async function fiber2() use (&$fiber2_results) {
    $fiber2_results[] = counter(); // Should be 1 (isolated)
    $fiber2_results[] = counter(); // Should be 2 (isolated)
    $fiber2_results[] = counter(); // Should be 3 (isolated)
}

// Run fibers concurrently
$task1 = async function() { return fiber1(); };
$task2 = async function() { return fiber2(); };

Scheduler::run([$task1(), $task2()]);

var_dump($fiber1_results);
var_dump($fiber2_results);

// Test 2: Global code should still work (backward compatibility)
echo "\n=== Test 2: Global code backward compatibility ===\n";

function global_counter() {
    static $value = 100;
    return ++$value;
}

echo "First call: " . global_counter() . "\n";  // Should be 101
echo "Second call: " . global_counter() . "\n"; // Should be 102

// Test 3: Multiple fibers with shared function
echo "\n=== Test 3: Multiple concurrent fibers ===\n";

$results = [];

async function increment_shared_static() {
    static $shared = 0;
    return ++$shared;
}

async function concurrent_task() {
    $res = [];
    for ($i = 0; $i < 3; $i++) {
        $res[] = increment_shared_static();
    }
    return $res;
}

$tasks = [];
for ($i = 0; $i < 3; $i++) {
    $tasks[] = async function() { return concurrent_task(); };
}

$results = Scheduler::run($tasks);

foreach ($results as $idx => $res) {
    echo "Task $idx results: ";
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

=== Test 3: Multiple concurrent fibers ===
Task 0 results: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
Task 1 results: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
Task 2 results: array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
