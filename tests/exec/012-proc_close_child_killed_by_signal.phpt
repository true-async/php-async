--TEST--
proc_close() after child process killed by signal (SIGSEGV)
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
if (DIRECTORY_SEPARATOR === '\\') die('skip Unix-only test');
$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) echo "skip no php executable defined";
?>
--FILE--
<?php
// Test that proc_close handles the case when a child process is killed by a signal.
// This reproduces a bug where async_wait_process gets ECHILD from waitpid
// (because the zombie was already reaped) and then libuv_process_event_start
// throws "Failed to monitor process: No child processes".

use function Async\spawn;
use function Async\await_all;

$php = getenv('TEST_PHP_EXECUTABLE');

// Test 1: kill child with SIGKILL before proc_close
echo "Test 1: SIGKILL\n";
$c1 = spawn(function() use ($php) {
    $process = proc_open(
        [$php, '-r', 'sleep(60);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    $status = proc_get_status($process);
    $pid = $status['pid'];

    // Kill child with SIGKILL
    posix_kill($pid, SIGKILL);

    // Give time for signal delivery and potential zombie reaping
    usleep(100000);

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    try {
        $exit_code = proc_close($process);
        echo "Exit code: $exit_code\n";
    } catch (\Throwable $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
});

// Test 2: kill child with SIGSEGV
echo "Test 2: SIGSEGV\n";
$c2 = spawn(function() use ($php) {
    $process = proc_open(
        [$php, '-r', 'sleep(60);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    $status = proc_get_status($process);
    $pid = $status['pid'];

    // Kill child with SIGSEGV
    posix_kill($pid, SIGSEGV);

    // Give time for signal delivery
    usleep(100000);

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    try {
        $exit_code = proc_close($process);
        echo "Exit code: $exit_code\n";
    } catch (\Throwable $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
});

// Test 3: child exits by itself with SIGSEGV (self-kill)
echo "Test 3: Self-SIGSEGV\n";
$c3 = spawn(function() use ($php) {
    $process = proc_open(
        [$php, '-r', 'posix_kill(posix_getpid(), SIGSEGV);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    // Give time for child to crash
    usleep(200000);

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    try {
        $exit_code = proc_close($process);
        echo "Exit code: $exit_code\n";
    } catch (\Throwable $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
});

await_all([$c1, $c2, $c3]);
echo "Done\n";
?>
--EXPECT--
Test 1: SIGKILL
Test 2: SIGSEGV
Test 3: Self-SIGSEGV
Exit code: -1
Exit code: -1
Exit code: -1
Done
