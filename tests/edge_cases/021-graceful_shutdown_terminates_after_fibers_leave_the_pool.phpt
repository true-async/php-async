--TEST--
Graceful shutdown still terminates a coroutine that catches its cancellation after more coroutines end than the fiber pool keeps
--INI--
async.shutdown_grace=300
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

$t0 = microtime(true);

register_shutdown_function(function () use ($t0) {
    $elapsed = microtime(true) - $t0;
    echo "ended after the grace: ", var_export($elapsed > 0.25 && $elapsed < 3.0, true), "\n";
});

spawn(function () {
    while (true) {
        try {
            delay(50);
        } catch (\Throwable $e) {
        }
    }
});

// Eight coroutines that end on the shutdown cancellation: more fibers finish than the pool takes back.
for ($i = 0; $i < 8; $i++) {
    spawn(function () {
        delay(10000);
    });
}

spawn(function () {
    delay(20);
    throw new \RuntimeException('boom');
});
?>
--EXPECTF--
%AWarning: 1 coroutine(s) did not end within async.shutdown_grace after the shutdown cancellation and are terminated in %s
%AFatal error: Uncaught RuntimeException: boom%A
%Aended after the grace: true
