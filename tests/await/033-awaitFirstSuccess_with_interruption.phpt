--TEST--
await_first_success() - With an unexpected interruption of execution.
--FILE--
<?php

use function Async\spawn;
use function Async\await_first_success;
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
    function() { throw new RuntimeException("error"); },
    function() { return "success"; },
    function() { return "another success"; },
];

spawn(fn() => throw new Exception("Unexpected interruption"));

$iterator = new TestIterator($functions);
$result = await_first_success($iterator);

echo "Result: {$result[0]}\n";
echo "end\n";

?>
--EXPECTF--
start

Fatal error: Uncaught Exception: Unexpected interruption in %s:%d
Stack trace:
#0 [internal function]: {closure:%s:%d}()
#1 {main}
  thrown in %s on line %d