--TEST--
await_any_or_fail() - Exception in iterator current() should stop process immediately
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_or_fail;
use function Async\suspend;

class ExceptionIterator implements Iterator
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
        // Throw exception on first iteration
        if ($this->position === 0) {
            throw new RuntimeException("Iterator exception during iteration");
        }
        
        return spawn($this->items[$this->position]);
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
    function() { return "fast"; },
    function() { suspend(); return "slow"; },
];

$iterator = new ExceptionIterator($functions);

try {
    $result = await_any_or_fail($iterator);
    echo "This should not be reached\n";
} catch (RuntimeException $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Caught exception: Iterator exception during iteration
end