--TEST--
Pool: beforeRelease callback - can destroy resource instead of returning to pool
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
    beforeRelease: function($r) {
        echo "beforeRelease($r)\n";
        // Return false to destroy instead of returning to pool
        return false;
    },
    max: 5
);

$r = $pool->tryAcquire();
echo "Acquired: $r\n";
echo "Idle: " . $pool->idleCount() . "\n";

$pool->release($r);
echo "After release:\n";
echo "Idle: " . $pool->idleCount() . "\n";
echo "Total: " . $pool->count() . "\n";

echo "Done\n";
?>
--EXPECT--
Created: 1
Acquired: 1
Idle: 0
beforeRelease(1)
Destroyed: 1
After release:
Idle: 0
Total: 0
Done
