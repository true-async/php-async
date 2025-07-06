--TEST--
awaitAll() - With concurrent iterator using suspend() in current()
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\suspend;

class ConcurrentIterator implements Iterator
{
    private $items = [];
    private $position = 0;

    public function __construct($items) {
        $this->items = $items;
    }

    public function rewind(): void {
        $this->position = 0;
    }

    public function current(): mixed {
        // Suspend during iteration to simulate concurrent access
        $position = $this->position;
        suspend();
        
        // Create coroutine after suspension
        return spawn(fn() => $this->items[$position]);
    }

    public function key(): mixed {
        return $this->position;
    }

    public function next(): void {
        $this->position++;
    }

    public function valid(): bool {
        return isset($this->items[$this->position]);
    }
}

echo "start\n";

$values = ["first", "second", "third"];
$iterator = new ConcurrentIterator($values);

$results = awaitAll($iterator);

echo "Results: " . implode(", ", $results) . "\n";
echo "Count: " . count($results) . "\n";
echo "end\n";

?>
--EXPECT--
start
Results: first, second, third
Count: 3
end