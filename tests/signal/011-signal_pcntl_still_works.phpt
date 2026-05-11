--TEST--
Async\signal() #109: pcntl_signal still intercepts when TrueAsync is loaded
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
if (!extension_loaded('pcntl')) echo "skip pcntl required";
if (!extension_loaded('posix')) echo "skip posix required";
?>
--EXTENSIONS--
pcntl
posix
--FILE--
<?php
// Verify: pcntl_signal() goes through zend_sigaction (which the patch
// does NOT touch) and still receives the signal correctly even though
// zend_signal_activate is a no-op under TrueAsync.

$got = null;
pcntl_signal(SIGUSR1, function (int $signo) use (&$got) {
    $got = $signo;
});

posix_kill(posix_getpid(), SIGUSR1);
pcntl_signal_dispatch();

echo "got=", var_export($got, true), "\n";
echo $got === SIGUSR1 ? "OK\n" : "FAIL\n";
?>
--EXPECT--
got=10
OK
