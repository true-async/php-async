--TEST--
Pool: construct - validation errors
--FILE--
<?php

use Async\Pool;

// min < 0
try {
    new Pool(factory: fn() => 1, min: -1);
    echo "ERROR: should have thrown\n";
} catch (ValueError $e) {
    echo "min < 0: " . $e->getMessage() . "\n";
}

// max < 1
try {
    new Pool(factory: fn() => 1, max: 0);
    echo "ERROR: should have thrown\n";
} catch (ValueError $e) {
    echo "max < 1: " . $e->getMessage() . "\n";
}

// min > max
try {
    new Pool(factory: fn() => 1, min: 10, max: 5);
    echo "ERROR: should have thrown\n";
} catch (ValueError $e) {
    echo "min > max: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECTF--
min < 0: Async\Pool::__construct(): Argument #6 ($min) must be >= 0
max < 1: Async\Pool::__construct(): Argument #7 ($max) must be >= 1
min > max: Async\Pool::__construct(): Argument #6 ($min) must be <= max
Done
