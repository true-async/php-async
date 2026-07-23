--TEST--
Pool: a resource in a suspending hook is still counted against max
--DESCRIPTION--
Admission is decided on idle + active. beforeAcquire and beforeRelease may
yield, and while they do the resource sits in neither — so a concurrent
acquirer read capacity that did not exist and built one past max, which the
pool never gave back. The resource has to stay counted for the whole hook.
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

$factoryCalls = 0;

$pool = new Async\Pool(
    function() use (&$factoryCalls) { return ++$factoryCalls; },
    null,                              // destructor
    null,                              // healthcheck
    function($r) { delay(30); return true; },   // beforeAcquire, suspends
    function($r) { delay(30); return true; },   // beforeRelease, suspends
    0,                                 // min
    1,                                 // max
);

// Put one resource into idle, so the next acquire goes through beforeAcquire.
$pool->release($pool->acquire());

$counts = [];

$user = spawn(function() use ($pool) {
    $r = $pool->acquire();
    delay(20);
    $pool->release($r);
});

$probe = spawn(function() use ($pool, &$counts) {
    for ($i = 0; $i < 8; $i++) {
        $counts[] = $pool->count();
        delay(10);
    }
});

await($user);
await($probe);

echo "resource ever counted nowhere: ", in_array(0, $counts, true) ? "yes" : "no", "\n";
echo "connections created: ", $factoryCalls, "\n";
echo "Done\n";
?>
--EXPECT--
resource ever counted nowhere: no
connections created: 1
Done
