--TEST--
await_all() - iterator with resource keys error
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "start\n";

class ResourceKeyIterator implements Iterator
{
    private $position = 0;
    private $keys;
    private $values;
    
    public function __construct() {
        $this->keys = [fopen('php://memory', 'r'), fopen('php://memory', 'r')];
        $this->values = [
            spawn(function() { return "value1"; }),
            spawn(function() { return "value2"; })
        ];
    }
    
    public function __destruct() {
        foreach ($this->keys as $key) {
            if (is_resource($key)) {
                fclose($key);
            }
        }
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
    $result = await_all(new ResourceKeyIterator());
    echo "ERROR: Should have failed with resource keys\n";
} catch (Async\AsyncException $e) {
    echo "Caught resource key error: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
Caught resource key error: Invalid key type: must be string, long or null
end