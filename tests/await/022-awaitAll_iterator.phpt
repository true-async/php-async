--TEST--
await_all() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
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
        // We create a coroutine inside the iteration because
        // this is the only way to ensure it will definitely be captured by await.
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

// Note that we cannot create coroutines before the iterator runs,
// because in that case the coroutines would start earlier,
// and the await expression wouldn’t have a chance to capture them.
$functions = [
    fn() => "success",
    fn() => throw new RuntimeException("error"),
    fn() => "another success",
];

$iterator = new TestIterator($functions);
$result = await_all($iterator);

$countOfResults = count($result[0]) == 2 ? "OK" : "FALSE: ".count($result[0]);
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