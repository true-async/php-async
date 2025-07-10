--TEST--
awaitAnyOf() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOf;
use function Async\await;

class TestIterator implements Iterator
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

$coroutines = [
    fn() => "first",
    fn() => "second",
    fn() => "third",
    fn() => "fourth",
];

$iterator = new TestIterator($coroutines);
$results = awaitAnyOf(2, $iterator);

$countOfResults = count($results) >= 2 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
end