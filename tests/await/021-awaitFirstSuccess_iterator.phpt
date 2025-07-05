--TEST--
awaitFirstSuccess() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
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
        delay(10);
        throw new RuntimeException("error");
    }),
    spawn(function() {
        delay(20);
        return "success";
    }),
    spawn(function() {
        delay(30);
        return "another success";
    }),
];

$iterator = new TestIterator($coroutines);
$result = awaitFirstSuccess($iterator);

echo "Result: {$result[0]}\n";
echo "end\n";

?>
--EXPECT--
start
Result: success
end