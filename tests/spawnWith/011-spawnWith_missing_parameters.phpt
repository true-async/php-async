--TEST--
Async\spawnWith: missing required parameters
--FILE--
<?php

use function Async\spawn_with;

echo "start\n";

// Test with no parameters
try {
    spawn_with();
} catch (ArgumentCountError $e) {
    echo "caught ArgumentCountError for no params: " . $e->getMessage() . "\n";
}

// Test with only provider
try {
    spawn_with(new Async\Scope());
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