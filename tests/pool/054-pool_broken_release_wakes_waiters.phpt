--TEST--
Pool: beforeRelease returning false (broken resource) wakes parked waiters
--DESCRIPTION--
Regression for issue #141. When a held resource is "broken" (beforeRelease
returns false), the pool destroys it instead of returning to idle. The slot
is freed but no idle resource is queued — without explicitly waking a waiter,
parked coroutines deadlock forever.
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\delay;

$factoryCalls = 0;
$alive = true;

$pool = new Async\Pool(
    function() use (&$factoryCalls) {
        delay(10);          // yield so concurrent acquires can park
        return ++$factoryCalls;
    },
    null,                   // destructor
    null,                   // healthcheck
    null,                   // beforeAcquire
    function($r) use (&$alive) { return $alive; },   // beforeRelease
    0,                      // min
    1,                      // max
);

$tasks = [];

// Coro 1 acquires the only slot, holds it long enough for coros 2 and 3
// to enter and park, then releases broken.
$tasks[] = spawn(function() use ($pool, &$alive) {
    $r = $pool->acquire();
    delay(30);              // let coros 2,3 park
    $alive = false;
    $pool->release($r);     // beforeRelease=false → destroy, slot free
    echo "coro1: released broken\n";
    $alive = true;          // future factories succeed
});

// Coros 2,3 enter while coro 1 holds the slot — they MUST park
// (total == max), then wake when coro 1's broken release frees the slot.
$tasks[] = spawn(function() use ($pool) {
    delay(5);
    $r = $pool->acquire();
    echo "coro2: acquired r=$r\n";
    $pool->release($r);
});

$tasks[] = spawn(function() use ($pool) {
    delay(5);
    $r = $pool->acquire();
    echo "coro3: acquired r=$r\n";
    $pool->release($r);
});

await_all($tasks);
echo "factoryCalls=$factoryCalls\n";
echo "done\n";
?>
--EXPECT--
coro1: released broken
coro2: acquired r=2
coro3: acquired r=2
factoryCalls=2
done
