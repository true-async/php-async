--TEST--
Async\graceful_shutdown(): explicit call from userland exits cleanly
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\graceful_shutdown;

// Covers async.c:814-826 — PHP_FUNCTION(Async_graceful_shutdown) entry
// point that hands control to ZEND_ASYNC_SHUTDOWN().

echo "start\n";

spawn(function () {
    while (true) {
        suspend();
    }
});

spawn(function () {
    suspend();
    echo "calling graceful_shutdown\n";
    graceful_shutdown();
    echo "after shutdown (should still print)\n";
});

suspend();
suspend();
suspend();

echo "end\n";

?>
--EXPECTF--
start
calling graceful_shutdown
%A
