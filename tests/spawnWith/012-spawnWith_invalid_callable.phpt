--TEST--
Async\spawnWith: invalid callable parameter
--FILE--
<?php

use function Async\spawn_with;
use Async\Scope;

echo "start\n";

$scope = new Scope();

// Test with non-callable parameter
try {
    spawn_with($scope, "not a callable");
} catch (TypeError $e) {
    echo "caught TypeError for string: " . $e->getMessage() . "\n";
}

// Test with array (not callable)
try {
    spawn_with($scope, []);
} catch (TypeError $e) {
    echo "caught TypeError for array\n";
}

// Test with null
try {
    spawn_with($scope, null);
} catch (TypeError $e) {
    echo "caught TypeError for null\n";
}

echo "end\n";

?>
--EXPECTF--
start
caught TypeError for string: %s
caught TypeError for array
caught TypeError for null
end