--TEST--
Async\spawnWith: invalid provider parameter
--FILE--
<?php

use function Async\spawnWith;

echo "start\n";

// Test with non-ScopeProvider parameter
try {
    spawnWith("not a provider", function() {});
} catch (TypeError $e) {
    echo "caught TypeError for string: " . $e->getMessage() . "\n";
}

// Test with array
try {
    spawnWith([], function() {});
} catch (TypeError $e) {
    echo "caught TypeError for array\n";
}

// Test with stdClass
try {
    spawnWith(new stdClass(), function() {});
} catch (TypeError $e) {
    echo "caught TypeError for stdClass\n";
}

echo "end\n";

?>
--EXPECTF--
start
caught TypeError for string: %s
caught TypeError for array
caught TypeError for stdClass
end