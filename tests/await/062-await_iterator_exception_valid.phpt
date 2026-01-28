--TEST--
await_all() - iterator exception in valid() method
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "start\n";

class FailingValidIterator implements Iterator
{
    private $position = 0;
    private $data = ['first'];
    
    public function rewind(): void {
        $this->position = 0;
    }
    
    public function current(): mixed {
        return spawn(function() {
            return $this->data[$this->position];
        });
    }
    
    public function key(): mixed {
        return $this->position;
    }
    
    public function next(): void {
        $this->position++;
    }
    
    public function valid(): bool {
        if ($this->position === 1) {
            throw new \RuntimeException("Iterator valid() failed");
        }
        return isset($this->data[$this->position]);
    }
}

try {
    $results = await_all(new FailingValidIterator());
    echo "ERROR: Should have thrown exception\n";
} catch (\RuntimeException $e) {
    echo "Caught valid() exception: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Caught exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Caught valid() exception: Iterator valid() failed
end