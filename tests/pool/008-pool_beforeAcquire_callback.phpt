--TEST--
Pool: beforeAcquire callback - validates idle resource before handing out
--FILE--
<?php

use Async\Pool;

$checkCount = 0;

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
    beforeAcquire: function($r) use (&$checkCount) {
        $checkCount++;
        echo "beforeAcquire($r): check #$checkCount\n";
        // Reject first check, accept subsequent
        return $checkCount > 1;
    },
    min: 1,
    max: 5
);

echo "Pool created\n";

// First tryAcquire - gets resource 1 from idle (min=1)
// beforeAcquire rejects it, resource destroyed
// New resource 2 created (freshly created - no beforeAcquire check)
$r = $pool->tryAcquire();
echo "Got: $r\n";

// Release and try again - now beforeAcquire should be called on idle resource
$pool->release($r);
echo "Released\n";

$r2 = $pool->tryAcquire();
echo "Got again: $r2\n";

echo "Done\n";
?>
--EXPECT--
Created: 1
Pool created
beforeAcquire(1): check #1
Destroyed: 1
Created: 2
Got: 2
Released
beforeAcquire(2): check #2
Got again: 2
Done
