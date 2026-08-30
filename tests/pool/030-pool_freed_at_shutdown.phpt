--TEST--
Pool: a pool freed with the object store at shutdown does not crash
--FILE--
<?php

use Async\Pool;

/* A cycle keeps the pool out of the destructor phase, so the engine frees it from
 * zend_objects_store_free_object_storage(). EG(active) is gone by then, so no object can be
 * created: closing a pool there must skip the "Pool is closed" exception it hands to waiters,
 * because there are none left to wake and the exception comes back as NULL. */
gc_disable();

$a = new stdClass();
$b = new stdClass();
$a->other = $b;
$b->other = $a;

$a->pool = new Pool(
    factory: static fn () => new stdClass(),
    min: 2,
    max: 4,
);

echo "Idle: " . $a->pool->idleCount() . "\n";
echo "Done\n";
?>
--EXPECT--
Idle: 2
Done
