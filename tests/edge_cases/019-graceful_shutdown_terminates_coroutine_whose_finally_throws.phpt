--TEST--
Graceful shutdown terminates a coroutine whose finally throws into a catch-all loop
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
            try {
                delay(50);
            } finally {
                throw new \RuntimeException('from finally');
            }
        } catch (\Throwable $e) {
        }
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
