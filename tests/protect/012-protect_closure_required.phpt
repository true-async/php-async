--TEST--
Async\protect: closure parameter is required
--FILE--
<?php

use function Async\protect;

echo "start\n";

// Test with no parameters
try {
    protect();
} catch (ArgumentCountError $e) {
    echo "caught ArgumentCountError: " . $e->getMessage() . "\n";
}

// Test with too many parameters
try {
    protect(function() {}, "extra param");
} catch (ArgumentCountError $e) {
    echo "caught ArgumentCountError for too many params\n";
}

echo "end\n";

?>
--EXPECTF--
start
caught ArgumentCountError: %s
caught ArgumentCountError for too many params
end