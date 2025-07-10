--TEST--
awaitAny() - Exception in iterator valid() should stop process immediately
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;

class ExceptionValidIterator implements Iterator
{
    private $items = [];
    private $position = 0;
    private $validCalls = 0;

    public function __construct($items) {
        $this->items = $items;
    }

    public function rewind(): void {
        $this->position = 0;
        $this->validCalls = 0;
    }

    public function current(): mixed {
        return spawn($this->items[$this->position]);
    }

    public function key(): mixed {
        return $this->position;
    }

    public function next(): void {
        $this->position++;
    }

    public function valid(): bool {
        $this->validCalls++;
        // Throw exception on third call to valid()
        if ($this->validCalls === 3) {
            throw new RuntimeException("Iterator valid() exception");
        }
        return isset($this->items[$this->position]);
    }
}

echo "start\n";

$functions = [
    function() { return "fast"; },
    function() { return "medium"; },
    function() { return "slow"; },
];

$iterator = new ExceptionValidIterator($functions);

try {
    $result = awaitAny($iterator);
    echo "This should not be reached\n";
} catch (RuntimeException $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Caught exception: Iterator valid() exception
end