--TEST--
Async\delay() after synchronous CPU work still waits (#185, stale libuv loop time)
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;
use Async\Scope;

// #185: a coroutine that burns CPU without yielding for longer than the timeout
// it then arms left libuv's cached loop->time stale, so delay() computed an
// already-past deadline and returned almost instantly instead of waiting. The
// reactor clock is now refreshed when a relative userland timeout is armed.
//
// The second coroutine's delay(50) is what exposes the bug: with a single timer
// libuv's relative poll timeout hides the staleness, but a concurrent timer makes
// the loop re-check the burning coroutine's timer against an already-advanced
// clock and fire it early.
//
// Timers only ever fire late, never early, so the lower bound cannot flake:
// with the bug delay(150) returns in ~0ms, with the fix it waits the full ~150ms.

function burn_cpu_ms(float $ms): void {
    $deadline = microtime(true) + $ms / 1000;
    while (microtime(true) < $deadline) {
        // busy loop, no yield point
    }
}

$main = spawn(function () {
    $scope = new Scope();

    $scope->spawn(function () {
        burn_cpu_ms(300);             // makes loop->time stale, longer than the delay below
        $before = microtime(true);
        delay(150);
        $elapsed = microtime(true) - $before;
        echo "delay waited: " . ($elapsed >= 0.1 ? "yes" : "no") . "\n";
    });

    $scope->spawn(function () {
        delay(50);                    // concurrent timer, keeps the loop from blocking on A's timer alone
    });

    $scope->awaitCompletion(\Async\timeout(5000));
});

await($main);
echo "done\n";

?>
--EXPECT--
delay waited: yes
done
