--TEST--
Async\signal() #109: unregistered SIGINT/SIGTERM still kill the process at OS level
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
// Verify: a PHP process with TrueAsync loaded but no registered handler
// for SIGINT/SIGTERM is killed by the signal exactly as the OS default
// action says. The patch in Zend/zend_signal.c must not change this —
// Ctrl-C semantics for unhandled signals are preserved.
//
// pcntl_fork + pcntl_waitpid gives us reliable WIFSIGNALED/WTERMSIG info.

function check(int $signum, string $name): void {
    $pid = pcntl_fork();
    if ($pid === 0) {
        // child: just sleep — no signal handlers registered
        usleep(3_000_000);
        exit(0);
    }
    usleep(200_000);
    posix_kill($pid, $signum);
    pcntl_waitpid($pid, $status);
    $sig = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0;
    echo "$name: ", ($sig === $signum ? "killed by $name" : "FAIL exit_sig=$sig"), "\n";
}

check(SIGINT,  'SIGINT');
check(SIGTERM, 'SIGTERM');
check(SIGUSR1, 'SIGUSR1');
echo "done\n";
?>
--EXPECT--
SIGINT: killed by SIGINT
SIGTERM: killed by SIGTERM
SIGUSR1: killed by SIGUSR1
done
