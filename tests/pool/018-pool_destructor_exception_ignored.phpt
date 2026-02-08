--TEST--
Pool: destructor exception - is ignored and doesn't propagate
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

// Close should not throw even though destructor throws
$pool->close();
echo "Closed without exception\n";

echo "Done\n";
?>
--EXPECT--
Pool created
Destructor for 1 throwing
Closed without exception
Done
