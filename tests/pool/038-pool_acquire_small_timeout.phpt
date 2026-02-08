--TEST--
Pool: acquire with small timeout fails quickly when no resources
--FILE--
<?php

use Async\Pool;
use Async\TimeoutException;
use function Async\spawn;
use function Async\await;

$pool = new Pool(
    factory: function() {
        return "resource";
    },
    max: 1
);

$result = spawn(function() use ($pool) {
    // Take the only resource
    $r = $pool->tryAcquire();
    $gotFirst = ($r !== null);

    $gotTimeout = false;
    // Another coroutine tries to acquire with small timeout (1ms)
    $inner = spawn(function() use ($pool, &$gotTimeout) {
        try {
            $pool->acquire(1);
        } catch (TimeoutException $e) {
            $gotTimeout = true;
        }
    });
    await($inner);

    $pool->release($r);

    // Now acquire should work
    $r2 = $pool->tryAcquire();
    $gotSecond = ($r2 !== null);

    return [$gotFirst, $gotTimeout, $gotSecond];
});

$res = await($result);
echo "First acquire: " . ($res[0] ? "success" : "failed") . "\n";
echo "Small timeout threw: " . ($res[1] ? "yes" : "no") . "\n";
echo "Second acquire after release: " . ($res[2] ? "success" : "failed") . "\n";
echo "Done\n";
?>
--EXPECT--
First acquire: success
Small timeout threw: yes
Second acquire after release: success
Done
