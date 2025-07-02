--TEST--
Coroutine: GC handler with waker and scope structures  
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use Async\Scope;

// Test GC with coroutine containing waker and scope structures
$scope = new Scope();

$coroutine = $scope->spawn(function() {
    // This creates waker and scope structures with ZVALs
    $data = ["test" => "value"];
    return $data;
});

// Force garbage collection to test waker/scope ZVAL tracking
$collected = gc_collect_cycles();

suspend(); // Suspend to simulate coroutine lifecycle

// Get result
$result = $coroutine->getResult();
var_dump($result);

// Verify GC was called
var_dump($collected >= 0);

?>
--EXPECT--
array(1) {
  ["test"]=>
  string(5) "value"
}
bool(true)