--TEST--
Pool: beforeRelease exception - treated as failure, resource destroyed
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    destructor: function($r) {
        echo "Destroyed: $r\n";
    },
    beforeRelease: function($r) {
        echo "beforeRelease($r) throwing\n";
        throw new RuntimeException("Release check failed");
    },
    max: 5
);

$r = $pool->tryAcquire();
echo "Acquired: $r\n";
echo "Idle before release: " . $pool->idleCount() . "\n";

$pool->release($r);
echo "Idle after release: " . $pool->idleCount() . "\n";
echo "Total: " . $pool->count() . "\n";

echo "Done\n";
?>
--EXPECT--
Acquired: 1
Idle before release: 0
beforeRelease(1) throwing
Destroyed: 1
Idle after release: 0
Total: 0
Done
