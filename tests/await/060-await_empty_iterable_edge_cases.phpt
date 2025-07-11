--TEST--
awaitAll() - empty iterators basic functionality
--FILE--
<?php

use function Async\awaitAll;

echo "start\n";

// Test EmptyIterator
$emptyIterator = new EmptyIterator();
[$results1, $exceptions1] = awaitAll($emptyIterator);
echo "EmptyIterator count: " . count($results1) . "\n";
echo "EmptyIterator type: " . gettype($results1) . "\n";

// Test empty SplFixedArray
$emptyFixedArray = new SplFixedArray(0);
[$results2, $exceptions2] = awaitAll($emptyFixedArray);
echo "Empty SplFixedArray count: " . count($results2) . "\n";

// Test custom empty iterator
class CustomEmptyIterator implements Iterator
{
    public function rewind(): void {}
    public function current(): mixed { return null; }
    public function key(): mixed { return null; }
    public function next(): void {}
    public function valid(): bool { return false; }
}

[$results3, $exceptions3] = awaitAll(new CustomEmptyIterator());
echo "CustomEmptyIterator count: " . count($results3) . "\n";

echo "end\n";

?>
--EXPECT--
start
EmptyIterator count: 0
EmptyIterator type: array
Empty SplFixedArray count: 0
CustomEmptyIterator count: 0
end