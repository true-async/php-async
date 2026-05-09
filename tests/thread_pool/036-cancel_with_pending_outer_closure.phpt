--TEST--
ThreadPool: cancel with pending tasks does not corrupt heap on shutdown
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--DESCRIPTION--
Regression: cancel() rejected pending futures by transferring a freshly built
exception to persistent memory; PHP's exception trace captured the running
outer closure as a stack-frame argument, so closure_transfer_obj() snapshotted
that closure's op_array into an arena. On the LOAD path back into the worker's
emalloc heap, the snapshot was destroyed but op_array_to_emalloc() did not
deep-copy `dynamic_func_defs`, so the loaded closure kept a dangling pointer
into the freed arena. destroy_op_array() on shutdown then walked the dangling
nested-function table → "zend_mm_heap corrupted" + segfault.
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await_all;

class Ctx {
    public ThreadPool $pool;
    public array $plannedActions = [];
}

$ctx = new Ctx();
$ctx->pool = new ThreadPool(2);

// Build a non-trivial outer closure (one that has a `static fn` literal,
// hence num_dynamic_func_defs > 0) and submit it through the pool. The
// outer closure must be reachable from the captured stack at cancel() time.
for ($i = 0; $i < 8; $i++) {
    $ctx->plannedActions['S'][] = function(Ctx $c) use ($i) {
        $c->pool->submit(static fn(int $idx): int => $idx, $i);
    };
}
$ctx->plannedActions['X'][] = function(Ctx $c) {
    $c->pool->cancel();
};

$h1 = spawn(function() use ($ctx) {
    foreach ($ctx->plannedActions['S'] as $a) $a($ctx);
});
$h2 = spawn(function() use ($ctx) {
    foreach ($ctx->plannedActions['X'] as $a) $a($ctx);
});
await_all([$h1, $h2]);

echo "ok\n";
?>
--EXPECT--
ok
