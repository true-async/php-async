--TEST--
Graceful shutdown terminates a coroutine that catches its cancellation, once async.shutdown_grace runs out
--INI--
async.shutdown_grace=300
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

$t0 = microtime(true);

register_shutdown_function(function () use ($t0) {
    $elapsed = microtime(true) - $t0;
    echo "ended within the grace: ", var_export($elapsed > 0.25 && $elapsed < 3.0, true), "\n";
});

spawn(function () {
    try {
        while (true) {
            try {
                delay(50);
            } catch (\Throwable $e) {
            }
        }
    } finally {
        // The termination is an exit: finally blocks do not run.
        echo "finally ran\n";
    }
});

spawn(function () {
    delay(20);
    throw new \RuntimeException('boom');
});
?>
--EXPECTF--
%AWarning: 1 coroutine(s) did not end within async.shutdown_grace after the shutdown cancellation and are terminated in %s
%AFatal error: Uncaught RuntimeException: boom%A
%Aended within the grace: true
