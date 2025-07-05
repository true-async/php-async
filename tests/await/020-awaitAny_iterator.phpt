--TEST--
awaitAny() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
use function Async\await;
use function Async\delay;

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

    public function current() {
        return $this->items[$this->position];
    }

    public function key() {
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
    spawn(function() {
        delay(50);
        return "slow";
    }),
    spawn(function() {
        delay(10);
        return "fast";
    }),
    spawn(function() {
        delay(30);
        return "medium";
    }),
];

$iterator = new TestIterator($coroutines);
$result = awaitAny($iterator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end