--TEST--
Pool: release on closed pool destroys resource
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: fn() => 1,
    destructor: function($r) {
        echo "Destroyed: $r\n";
    }
);

$r = $pool->tryAcquire();
echo "Acquired: $r\n";

$pool->close();
echo "Pool closed\n";

$pool->release($r);
echo "Released (should destroy)\n";

echo "Done\n";
?>
--EXPECT--
Acquired: 1
Pool closed
Destroyed: 1
Released (should destroy)
Done
