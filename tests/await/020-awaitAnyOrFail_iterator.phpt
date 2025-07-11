--TEST--
awaitAnyOrFail() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOrFail;
use function Async\await;
use function Async\suspend;

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
    function() {
        suspend();
        return "slow";
    },
    function() {
        return "fast";
    },
    function() {
        return "medium";
    },
];

$iterator = new TestIterator($functions);
$result = awaitAnyOrFail($iterator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end