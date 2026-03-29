--TEST--
proc_close() when child zombie was already reaped externally
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
if (DIRECTORY_SEPARATOR === '\\') die('skip Unix-only test');
$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) echo "skip no php executable defined";
?>
--FILE--
<?php
// Simulates the FrankenPHP scenario: child process dies, then an external
// mechanism (Go runtime, pcntl handler, etc.) reaps the zombie via waitpid(-1)
// before PHP's proc_close gets to it.
//
// Without fix: "Failed to monitor process N: No child processes" exception
// With fix: proc_close returns -1 gracefully

use function Async\spawn;

$php = getenv('TEST_PHP_EXECUTABLE');

echo "Test: proc_close after external reap\n";

$c = spawn(function() use ($php) {
    $process = proc_open(
        [$php, '-r', 'exit(42);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    $status = proc_get_status($process);
    $pid = $status['pid'];

    // Wait for child to actually exit by reading stdout until EOF.
    // This is deterministic regardless of ASAN/valgrind slowdown.
    stream_get_contents($pipes[1]);

    // Simulate external reaping (like Go runtime doing waitpid(-1))
    // This steals the zombie before proc_close can get it.
    $reap_status = 0;
    $reaped = pcntl_waitpid($pid, $reap_status, WNOHANG);
    echo "Zombie reaped externally, exit=" . pcntl_wexitstatus($reap_status) . "\n";

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    try {
        $exit_code = proc_close($process);
        echo "proc_close returned: $exit_code\n";
    } catch (\Throwable $e) {
        echo "Exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
});

Async\await($c);
echo "Done\n";
?>
--EXPECT--
Test: proc_close after external reap
Zombie reaped externally, exit=42
proc_close returned: -1
Done
