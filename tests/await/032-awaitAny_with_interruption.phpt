--TEST--
awaitAny() - With an unexpected interruption of execution.
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
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
    function() { suspend(); return "slow"; },
    function() { return "fast"; },
    function() { suspend(); return "medium"; },
];

spawn(fn() => throw new Exception("Unexpected interruption"));

$iterator = new TestIterator($functions);
$result = awaitAny($iterator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECTF--
start

Fatal error: Uncaught Exception: Unexpected interruption in %s:%d
Stack trace:
#0 [internal function]: {closure:%s:%d}()
#1 {main}
  thrown in %s on line %d