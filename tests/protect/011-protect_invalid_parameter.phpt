--TEST--
Async\protect: invalid parameter types
--FILE--
<?php

use function Async\protect;

echo "start\n";

// Test with non-closure parameter
try {
    protect("not a closure");
} catch (TypeError $e) {
    echo "caught TypeError: " . $e->getMessage() . "\n";
}

// Test with array
try {
    protect([]);
} catch (TypeError $e) {
    echo "caught TypeError for array\n";
}

// Test with object
try {
    protect(new stdClass());
} catch (TypeError $e) {
    echo "caught TypeError for object\n";
}

echo "end\n";

?>
--EXPECTF--
start
caught TypeError: %s
caught TypeError for array
caught TypeError for object
end