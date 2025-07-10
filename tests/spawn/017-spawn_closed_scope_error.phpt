--TEST--
spawn() - error when spawning in closed scope
--FILE--
<?php

use function Async\spawn;

echo "start\n";

// Test 1: Spawn in disposed scope should fail
$scope = \Async\Scope::inherit();
echo "Scope created\n";

// Dispose the scope
$scope->dispose();
echo "Scope disposed\n";

try {
    $coroutine = $scope->spawn(function() {
        return "test";
    });
    echo "ERROR: Should have thrown exception\n";
} catch (Async\AsyncException $e) {
    echo "Caught expected error: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Caught exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Test 2: Verify scope is actually closed
echo "Scope is closed: " . ($scope->isClosed() ? "true" : "false") . "\n";
echo "Scope is finished: " . ($scope->isFinished() ? "true" : "false") . "\n";

// Test 3: Spawn in safely disposed scope should also fail
$scope2 = \Async\Scope::inherit();
$scope2->disposeSafely();
echo "Scope safely disposed\n";

try {
    $coroutine2 = $scope2->spawn(function() {
        return "test2";
    });
    echo "ERROR: Should have thrown exception\n";
} catch (Async\AsyncException $e) {
    echo "Caught expected error for safely disposed: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Caught exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
Scope created
Scope disposed
Caught expected error: Cannot spawn a coroutine in a closed scope
Scope is closed: true
Scope is finished: true
Scope safely disposed
Caught expected error for safely disposed: Cannot spawn a coroutine in a closed scope
end