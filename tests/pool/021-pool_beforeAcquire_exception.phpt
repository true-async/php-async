--TEST--
Pool: beforeAcquire exception - treated as validation failure, resource destroyed
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
    beforeAcquire: function($r) {
        echo "beforeAcquire($r) throwing\n";
        throw new RuntimeException("Validation failed");
    },
    min: 1,
    max: 2
);

echo "Pool created\n";

// tryAcquire should:
// 1. Get resource 1 from idle (min=1 pre-warmed)
// 2. beforeAcquire throws -> resource destroyed
// 3. Create new resource 2 (no beforeAcquire check for fresh resources)
// 4. Return resource 2
$r = $pool->tryAcquire();
echo "Result: $r\n";

echo "Done\n";
?>
--EXPECT--
Created: 1
Pool created
beforeAcquire(1) throwing
Destroyed: 1
Created: 2
Result: 2
Done
