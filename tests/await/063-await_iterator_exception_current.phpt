--TEST--
await_all() - iterator exception in current() method
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "start\n";

class FailingCurrentIterator implements Iterator
{
    private $position = 0;
    private $data = ['first', 'second'];
    
    public function rewind(): void {
        $this->position = 0;
    }
    
    public function current(): mixed {
        if ($this->position === 1) {
            throw new \RuntimeException("Iterator current() failed");
        }
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
        return isset($this->data[$this->position]);
    }
}

try {
    $results = await_all(new FailingCurrentIterator());
    echo "ERROR: Should have thrown exception\n";
} catch (\RuntimeException $e) {
    echo "Caught current() exception: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Caught exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Caught current() exception: Iterator current() failed
end