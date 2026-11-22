--TEST--
Pool: the healthcheck destroys a dead idle resource and leaves its replacement to the next acquire
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

// The healthcheck runs in the scheduler context, where a factory cannot
// suspend and an exception it throws would stop the reactor. A replacement is
// created by the coroutine that asks for it.

$created = 0;

$pool = new Pool(
    factory: function () use (&$created) {
        if ($created > 0) {
            delay(10); // a connect waits for the network
        }
        return ++$created;
    },
    healthcheck: fn ($r) => $r !== 1,
    min: 1,
    max: 2,
    healthcheckInterval: 20,
);

$other = spawn(function () {
    for ($i = 0; $i < 5; $i++) {
        delay(20);
    }
    return "unrelated coroutine finished";
});

delay(100);
echo "after the healthcheck: count ", $pool->count(), ", created ", $created, "\n";
echo await($other), "\n";

$r = $pool->acquire();
echo "acquired #$r, created ", $created, "\n";
$pool->release($r);
$pool->close();
echo "done\n";
?>
--EXPECT--
after the healthcheck: count 0, created 1
unrelated coroutine finished
acquired #2, created 2
done
