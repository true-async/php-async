--TEST--
Pool: healthcheck callback throwing treats the resource as unhealthy
--FILE--
<?php

use Async\Pool;
use function Async\delay;

// Covers pool.c:561-563 — pool_call_healthcheck() EG(exception) branch
// that clears the exception and marks the resource as unhealthy.

$nextId = 0;
$destroyed = [];
$checked = 0;

$pool = new Pool(
    factory: function () use (&$nextId) {
        return ++$nextId;
    },
    destructor: function ($r) use (&$destroyed) {
        $destroyed[] = $r;
    },
    healthcheck: function ($r) use (&$checked) {
        $checked++;
        if ($r === 1) {
            throw new \RuntimeException("boom on #$r");
        }
        return true;
    },
    min: 2,
    max: 4,
    healthcheckInterval: 25,
);

delay(100);

// Resource #1 threw → treated as unhealthy → destroyed.
var_dump(in_array(1, $destroyed));
var_dump($checked >= 1);

$pool->close();

echo "done\n";

?>
--EXPECT--
bool(true)
bool(true)
done
