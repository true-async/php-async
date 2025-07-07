--TEST--
Async\spawnWith: missing required parameters
--FILE--
<?php

use function Async\spawnWith;

echo "start\n";

// Test with no parameters
try {
    spawnWith();
} catch (ArgumentCountError $e) {
    echo "caught ArgumentCountError for no params: " . $e->getMessage() . "\n";
}

// Test with only provider
try {
    spawnWith(new Async\Scope());
} catch (ArgumentCountError $e) {
    echo "caught ArgumentCountError for missing callable\n";
}

echo "end\n";

?>
--EXPECTF--
start
caught ArgumentCountError for no params: %s
caught ArgumentCountError for missing callable
end