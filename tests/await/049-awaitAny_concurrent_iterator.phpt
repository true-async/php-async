--TEST--
awaitAny() - With concurrent iterator using suspend() in current()
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
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
        echo "Current item at position: {$this->position}\n";
        return spawn($this->items[$this->position]);
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

$functions = [
    function() { suspend(); suspend(); return "slow"; },
    function() { return "fast"; },
    function() { suspend(); return "medium"; },
];

$iterator = new ConcurrentIterator($functions);

spawn(function() {
    // Simulate some processing
    for ($i = 1; $i <= 3; $i++) {
        echo "Processing item $i\n";
        suspend();
    }
});

$result = awaitAny($iterator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Processing item 1
Processing item 2
Current item at position: 0
Processing item 3
Current item at position: 1
Current item at position: 2
Result: fast
end