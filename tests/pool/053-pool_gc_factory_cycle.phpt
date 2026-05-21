--TEST--
Pool: a reference cycle through a callable handler is collectable (no leak)
--DESCRIPTION--
Covers async_pool_get_gc(). The pool's factory/destructor/healthcheck/
beforeAcquire/beforeRelease callables (and the circuit breaker strategy)
must be reported to the cycle collector. Otherwise a cycle that runs
holder -> Pool -> factory-closure -> holder is invisible to GC and the
Pool object leaks. Regression test for that get_gc omission.
--FILE--
<?php

use Async\Pool;

// 1. Cycle through the factory closure.
$holder = new stdClass();
$holder->pool = new Pool(factory: function () use ($holder) {
    return $holder->id ?? 1;
});
unset($holder);
gc_collect_cycles();
echo "factory cycle collected\n";

// 2. Cycle through beforeRelease as well (same get_gc path).
$holder2 = new stdClass();
$holder2->pool = new Pool(
    factory: function () { return 1; },
    beforeRelease: function ($r) use ($holder2) { return true; },
);
unset($holder2);
gc_collect_cycles();
echo "beforeRelease cycle collected\n";

echo "done\n";
?>
--EXPECT--
factory cycle collected
beforeRelease cycle collected
done
