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
        $this->position = 0;
    }

    public function current(): mixed {
        // Suspend during iteration
        $position = $this->position;
        suspend();
        
        return spawn($this->items[$position]);
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

$functions = [
    function() { suspend(); suspend(); return "slow"; },
    function() { return "fast"; },
    function() { suspend(); return "medium"; },
];

$iterator = new ConcurrentIterator($functions);

$result = awaitAny($iterator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end