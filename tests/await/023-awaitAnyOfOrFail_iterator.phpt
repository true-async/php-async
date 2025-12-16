--TEST--
await_any_of_or_fail() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of_or_fail;
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
$results = await_any_of_or_fail(2, $iterator);

$countOfResults = count($results) >= 2 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
end