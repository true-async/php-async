--TEST--
awaitAllOrFail() - Exception in iterator next() should stop process immediately
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;

class ExceptionNextIterator implements Iterator
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
        return spawn(fn() => $this->items[$this->position]);
    }

    public function key(): mixed {
        return $this->position;
    }

    public function next(): void {
        // Throw exception when moving to next position
        if ($this->position === 0) {
            throw new RuntimeException("Iterator next() exception");
        }
        $this->position++;
    }

    public function valid(): bool {
        return isset($this->items[$this->position]);
    }
}

echo "start\n";

$values = ["first", "second", "third"];
$iterator = new ExceptionNextIterator($values);

try {
    $results = awaitAllOrFail($iterator);
    echo "This should not be reached\n";
} catch (RuntimeException $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Caught exception: Iterator next() exception
end