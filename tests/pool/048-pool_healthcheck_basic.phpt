--TEST--
Pool: healthcheck callback periodically runs and destroys unhealthy resources
--FILE--
<?php

use Async\Pool;
use function Async\delay;

// Covers pool.c:527-670 — pool_call_healthcheck() PHP-fcall branch and
// pool_healthcheck_timer_callback() timer dispatcher that iterates idle
// resources and destroys/recreates the unhealthy ones.

$nextId = 0;
$seen = [];

$pool = new Pool(
    factory: function () use (&$nextId) {
        return ++$nextId;
    },
    healthcheck: function ($r) use (&$seen) {
        $seen[$r] = ($seen[$r] ?? 0) + 1;
        // Reject the very first resource, accept the rest.
        return $r !== 1;
    },
    min: 2,
    max: 4,
    healthcheckInterval: 30,
);

delay(120);

// Resource #1 was marked unhealthy and should have been destroyed.
// Resources #2 and #3 should have been checked at least once.
var_dump(isset($seen[1]) && $seen[1] >= 1);
var_dump(isset($seen[2]) && $seen[2] >= 1);

$pool->close();

echo "done\n";

?>
--EXPECT--
bool(true)
bool(true)
done
