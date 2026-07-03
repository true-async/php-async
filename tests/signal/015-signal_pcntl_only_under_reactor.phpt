--TEST--
pcntl_signal() under a running reactor: delivery flows through the reactor, process still exits
--EXTENSIONS--
pcntl
posix
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
?>
--FILE--
<?php
// pcntl-only subscription while the reactor is running (no Async\signal
// waiters): zend_sigaction() delegates to the reactor, which arms an
// unref'd uv_signal. Checks both delivery (forwarded to the Zend chain)
// and that the handle does not keep the loop alive — the script must
// exit on its own after the coroutine finishes.

use function Async\delay;
use function Async\spawn;

spawn(function () {
    delay(20); /* make sure the reactor is up */
    pcntl_signal(SIGUSR1, function () {
        echo "pcntl\n";
    });

    posix_kill(posix_getpid(), SIGUSR1);
    delay(50);
    pcntl_signal_dispatch();
    echo "end\n";
});
?>
--EXPECT--
pcntl
end
