--TEST--
awaitAllWithErrors() - with Iterator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllWithErrors;
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

    public function current(): mixed {
        return $this->items[$this->position];
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
    spawn(function() {
        return "success";
    }),
    spawn(function() {
        throw new RuntimeException("error");
    }),
    spawn(function() {
        return "another success";
    }),
];

$iterator = new TestIterator($coroutines);
$result = awaitAllWithErrors($iterator);

$countOfResults = count($result[0]) == 3 ? "OK" : "FALSE: ".count($result[0]);
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