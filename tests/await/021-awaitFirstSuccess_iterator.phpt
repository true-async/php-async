--TEST--
awaitFirstSuccess() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
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
// and the await expression wouldn't have a chance to capture them.
$functions = [
    fn() => throw new RuntimeException("error"),
    fn() => "success",
    fn() => "another success",
];

$iterator = new TestIterator($functions);
$result = awaitFirstSuccess($iterator);

echo "Result: {$result[0]}\n";
echo "end\n";

?>
--EXPECT--
start
Result: success
end