--TEST--
Async\spawnWith: invalid callable parameter
--FILE--
<?php

use function Async\spawnWith;
use Async\Scope;

echo "start\n";

$scope = new Scope();

// Test with non-callable parameter
try {
    spawnWith($scope, "not a callable");
} catch (TypeError $e) {
    echo "caught TypeError for string: " . $e->getMessage() . "\n";
}

// Test with array (not callable)
try {
    spawnWith($scope, []);
} catch (TypeError $e) {
    echo "caught TypeError for array\n";
}

// Test with null
try {
    spawnWith($scope, null);
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