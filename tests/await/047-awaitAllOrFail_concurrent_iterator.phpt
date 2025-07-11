--TEST--
awaitAllOrFail() - With concurrent iterator using suspend() in current()
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;
use function Async\suspend;

class ConcurrentIterator implements Iterator
{
    private $items = [];
    private $position = 0;

    public function __construct($items) {
        $this->items = $items;
    }

    public function rewind(): void {
        suspend(); // Simulate concurrent access
        $this->position = 0;
    }

    public function current(): mixed {
        // Create coroutine after suspension
        echo "Current item: {$this->items[$this->position]}\n";
        return spawn(fn() => $this->items[$this->position]);
    }

    public function key(): mixed {
        return $this->position;
    }

    public function next(): void {
        suspend(); // Simulate concurrent access
        $this->position++;
    }

    public function valid(): bool {
        return isset($this->items[$this->position]);
    }
}

echo "start\n";

$values = ["first", "second", "third"];
$iterator = new ConcurrentIterator($values);

spawn(function() {
    // Simulate some processing
    for ($i = 1; $i <= 5; $i++) {
        echo "Processing item $i\n";
        suspend();
    }
});

$results = awaitAllOrFail($iterator);

echo "Results: " . implode(", ", $results) . "\n";
echo "Count: " . count($results) . "\n";
echo "end\n";

?>
--EXPECT--
start
Processing item 1
Processing item 2
Current item: first
Processing item 3
Current item: second
Processing item 4
Current item: third
Processing item 5
Results: first, second, third
Count: 3
end