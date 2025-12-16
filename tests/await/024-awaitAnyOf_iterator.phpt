--TEST--
await_any_of() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of;
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
    fn() => throw new RuntimeException("error"),
    fn() => "third",
    fn() => "fourth",
];

$iterator = new TestIterator($coroutines);
$result = await_any_of(2, $iterator);

$countOfResults = count($result[0]) >= 2 ? "OK" : "FALSE: ".count($result[0]);
$countOfErrors = count($result[1]) == 1 ? "OK" : "FALSE: ".count($result[1]);

echo "Count of results: $countOfResults\n";
echo "Count of errors: $countOfErrors\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
Count of errors: OK
end