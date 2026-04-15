--TEST--
proc_close() after child process killed by signal (SIGSEGV)
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
if (DIRECTORY_SEPARATOR === '\\') die('skip Unix-only test');
if (!function_exists("posix_kill")) die('skip ext/posix required');
$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) echo "skip no php executable defined";
// ASAN installs its own SIGSEGV handler via sigaction. When the child process
// self-sends SIGSEGV (posix_kill(getpid(), SIGSEGV)), ASAN intercepts it and
// calls _exit(1) instead of letting the process die from the signal.
// This makes proc_close() return 1 (normal exit) instead of -11 (signal death).
if (getenv('USE_ZEND_ALLOC') === '0') die('skip ASAN intercepts SIGSEGV and converts it to exit(1)');
?>
--FILE--
<?php
// Test that proc_close correctly returns the negative signal number
// when a child process is killed by a signal.

use function Async\spawn;

$php = getenv('TEST_PHP_EXECUTABLE');

// Test 1: kill child with SIGKILL
$c = spawn(function() use ($php) {
    $process = proc_open(
        [$php, '-r', 'sleep(60);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    $status = proc_get_status($process);
    posix_kill($status['pid'], SIGKILL);
    usleep(100000);

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);
    echo "SIGKILL: $exit_code\n";
});
Async\await($c);

// Test 2: kill child with SIGSEGV
$c = spawn(function() use ($php) {
    $process = proc_open(
        [$php, '-r', 'sleep(60);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    $status = proc_get_status($process);
    posix_kill($status['pid'], SIGSEGV);
    usleep(100000);

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);
    echo "SIGSEGV: $exit_code\n";
});
Async\await($c);

// Test 3: child self-kills with SIGSEGV
$c = spawn(function() use ($php) {
    $process = proc_open(
        [$php, '-r', 'posix_kill(posix_getpid(), SIGSEGV);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    usleep(200000);

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);
    echo "Self-SIGSEGV: $exit_code\n";
});
Async\await($c);

echo "Done\n";
?>
--EXPECT--
SIGKILL: -9
SIGSEGV: -11
Self-SIGSEGV: -11
Done
