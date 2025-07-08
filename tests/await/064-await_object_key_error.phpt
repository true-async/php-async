--TEST--
awaitAll() - iterator with object keys error
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "start\n";

class ObjectKeyIterator implements Iterator
{
    private $position = 0;
    private $keys;
    private $values;
    
    public function __construct() {
        $this->keys = [new stdClass(), new stdClass()];
        $this->values = [
            spawn(function() { return "value1"; }),
            spawn(function() { return "value2"; })
        ];
    }
    
    public function rewind(): void {
        $this->position = 0;
    }
    
    public function current(): mixed {
        return $this->values[$this->position] ?? null;
    }
    
    public function key(): mixed {
        return $this->keys[$this->position] ?? null;
    }
    
    public function next(): void {
        $this->position++;
    }
    
    public function valid(): bool {
        return $this->position < count($this->keys);
    }
}

try {
    $result = awaitAll(new ObjectKeyIterator());
    echo "ERROR: Should have failed with object keys\n";
} catch (Throwable $e) {
    echo "Caught object key error: " . get_class($e) . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
Caught object key error: %s
end