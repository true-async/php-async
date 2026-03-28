--TEST--
Pool: destructor exception propagates from close()
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    destructor: function($r) {
        echo "Destructor for $r throwing\n";
        throw new RuntimeException("Destructor failed");
    },
    min: 1
);

echo "Pool created\n";

try {
    $pool->close();
    echo "ERROR: should have thrown\n";
} catch (RuntimeException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Pool created
Destructor for 1 throwing
Caught: Destructor failed
Done
