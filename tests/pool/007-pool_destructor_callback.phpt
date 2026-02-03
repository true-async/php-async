--TEST--
Pool: destructor callback - called when resource destroyed
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        $id = ++$c;
        echo "Created: $id\n";
        return $id;
    },
    destructor: function($r) {
        echo "Destroyed: $r\n";
    },
    min: 1,
    max: 2
);

echo "Pool created\n";

// Acquire and release
$r = $pool->tryAcquire();
echo "Acquired: $r\n";
$pool->release($r);
echo "Released (resource returned to pool)\n";

// Close - should destroy idle resource
$pool->close();
echo "Closed\n";

echo "Done\n";
?>
--EXPECT--
Created: 1
Pool created
Acquired: 1
Released (resource returned to pool)
Destroyed: 1
Closed
Done
