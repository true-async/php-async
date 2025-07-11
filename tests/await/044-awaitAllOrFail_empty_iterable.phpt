--TEST--
awaitAllOrFail() - with empty iterable
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;
use function Async\await;

// Test with empty array
echo "start\n";

$results = awaitAllOrFail([]);

echo "Empty array count: " . count($results) . "\n";
echo "Empty array type: " . gettype($results) . "\n";

// Test with empty ArrayObject
$emptyArrayObject = new ArrayObject([]);
$results2 = awaitAllOrFail($emptyArrayObject);

echo "Empty ArrayObject count: " . count($results2) . "\n";
echo "Empty ArrayObject type: " . gettype($results2) . "\n";

// Test with empty generator
function emptyGenerator() {
    return;
    yield; // unreachable
}

$results3 = awaitAllOrFail(emptyGenerator());

echo "Empty generator count: " . count($results3) . "\n";
echo "Empty generator type: " . gettype($results3) . "\n";

echo "end\n";

?>
--EXPECT--
start
Empty array count: 0
Empty array type: array
Empty ArrayObject count: 0
Empty ArrayObject type: array
Empty generator count: 0
Empty generator type: array
end