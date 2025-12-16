--TEST--
await_all_or_fail() - with duplicates
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_or_fail;

class MyIterator implements Iterator
{
    private $coroutine = null;
    private $position = 0;

    public function __construct(private int $maxPos) {
    }

    public function rewind(): void {
        $this->position = 0;
        $this->coroutine = spawn(function() {
            echo "Coroutine started\n";
            return "result";
        });
    }

    public function current(): mixed {
        // Always return the same coroutine
        return $this->coroutine;
    }

    public function key(): mixed {
        return $this->position;
    }

    public function next(): void {
        $this->position++;
    }

    public function valid(): bool {
        return $this->position <= $this->maxPos;
    }
}

echo "start\n";

$iterator = new MyIterator(3);

try {
    $result = await_any_or_fail($iterator);
} catch (RuntimeException $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
}

var_dump($result);

echo "end\n";

?>
--EXPECT--
start
Coroutine started
string(6) "result"
end