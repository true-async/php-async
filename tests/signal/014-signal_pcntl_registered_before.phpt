--TEST--
Async\signal() forwards a valid siginfo to a pre-registered pcntl handler (no SEGV)
--EXTENSIONS--
pcntl
posix
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
?>
--FILE--
<?php
// pcntl_signal() first, Async\signal() second: uv_signal_start() takes
// over the sigaction and the uv callback forwards the Zend handler
// chain. pcntl's SA_SIGINFO handler copies *siginfo — forwarding with
// siginfo=NULL crashed the process on the first delivery.

use Async\Signal;
use function Async\await;
use function Async\delay;
use function Async\signal;
use function Async\spawn;

pcntl_signal(SIGUSR2, function () {
    echo "pcntl\n";
});

spawn(function () {
    $sig = await(signal(Signal::SIGUSR2));
    echo "async: {$sig->name}\n";
});

spawn(function () {
    delay(50);
    posix_kill(posix_getpid(), SIGUSR2);
    delay(100);
    pcntl_signal_dispatch();
    echo "end\n";
});
?>
--EXPECT--
async: SIGUSR2
pcntl
end
