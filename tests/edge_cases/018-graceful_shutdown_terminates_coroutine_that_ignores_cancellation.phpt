--TEST--
Graceful shutdown terminates a coroutine that catches its cancellation, once async.shutdown_grace runs out
--INI--
async.shutdown_grace=300
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

$t0 = microtime(true);
$finallyRan = false;

register_shutdown_function(function () use ($t0, &$finallyRan) {
    $elapsed = microtime(true) - $t0;
    echo "ended after the grace: ", var_export($elapsed > 0.25 && $elapsed < 3.0, true), "\n";
    echo "finally ran: ", var_export($finallyRan, true), "\n";
});

spawn(function () use (&$finallyRan) {
    try {
        while (true) {
            try {
                delay(50);
            } catch (\Throwable $e) {
            }
        }
    } finally {
        // The termination is an exit: finally blocks do not run.
        $finallyRan = true;
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
%Aended after the grace: true
finally ran: false
