<?php
/**
 * Simulates how run-tests.php launches a test:
 *   - Creates pipes for stdin/stdout/stderr
 *   - Runs the test script via shell with "2>&1"
 *   - Reads stdout via stream_select loop
 *   - Detects hang when pipe EOF never arrives
 *
 * Usage: php simulate_run_tests.php
 */

$php = PHP_BINARY;
$test_file = __DIR__ . '/curl/025-write_file_broken_pipe.php';

if (!file_exists($test_file)) {
    die("Test file not found: $test_file\n");
}

// Exactly how run-tests.php builds the command
$cmd = "$php -n -d extension=async -d extension=curl -f " . escapeshellarg($test_file) . " 2>&1";

echo "=== simulate_run_tests.php ===\n";
echo "CMD: $cmd\n";
echo "PID: " . getmypid() . "\n\n";

// Same descriptorspec as run-tests.php system_with_timeout()
$descriptorspec = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w'],  // stderr
];

$proc = proc_open($cmd, $descriptorspec, $pipes, __DIR__, null, ['suppress_errors' => true]);
if (!$proc) {
    die("proc_open failed\n");
}

// Close stdin (same as run-tests.php)
fclose($pipes[0]);
unset($pipes[0]);

$status = proc_get_status($proc);
echo "Child PID: {$status['pid']}\n\n";

$timeout = 15; // 15 seconds (run-tests uses 60)
$data = '';
$iteration = 0;

echo "--- Entering stream_select loop (timeout={$timeout}s) ---\n";

while (true) {
    $r = $pipes;
    $w = null;
    $e = null;

    echo "stream_select calling\n";
    $t_start = microtime(true);
    $n = @stream_select($r, $w, $e, $timeout);
    //$n = 1;
    $t_elapsed = microtime(true) - $t_start;
    echo "stream_select called n = $n\n";

    $iteration++;

    if ($n === false) {
        echo "[iter=$iteration] stream_select returned FALSE (error), breaking\n";
        break;
    }

    if ($n === 0) {
        echo "[iter=$iteration] stream_select TIMED OUT after {$t_elapsed}s — THIS IS THE HANG!\n";

        // Dump process info for debugging
        $child_status = proc_get_status($proc);
        echo "\n=== DIAGNOSIS ===\n";
        echo "Child running: " . ($child_status['running'] ? 'YES' : 'NO') . "\n";
        echo "Child exitcode: {$child_status['exitcode']}\n";
        echo "Child termsig: {$child_status['termsig']}\n";
        echo "Child stopsig: {$child_status['stopsig']}\n";

        // Find php -S processes
        echo "\n--- Processes holding pipes open ---\n";
        $child_pid = $child_status['pid'];
        // List all php processes from our tree
        exec("ps --forest -o pid,ppid,stat,cmd -g " . posix_getsid(getmypid()) . " 2>/dev/null", $ps_out);
        echo implode("\n", $ps_out) . "\n";

        // Check /proc/*/fd for pipe inodes
        echo "\n--- Pipe FDs of this process (simulate_run_tests.php PID=" . getmypid() . ") ---\n";
        exec("ls -la /proc/" . getmypid() . "/fd 2>/dev/null | grep pipe", $my_fds);
        echo implode("\n", $my_fds) . "\n";

        // Find ALL processes that share our pipe inodes
        $pipe_inodes = [];
        exec("ls -la /proc/" . getmypid() . "/fd 2>/dev/null", $all_fds);
        foreach ($all_fds as $line) {
            if (preg_match('/pipe:\[(\d+)\]/', $line, $m)) {
                $pipe_inodes[$m[1]] = true;
            }
        }
        if ($pipe_inodes) {
            echo "\n--- Searching ALL processes for matching pipe inodes ---\n";
            foreach (glob('/proc/[0-9]*/fd/*') as $fd_path) {
                $target = @readlink($fd_path);
                if ($target && preg_match('/pipe:\[(\d+)\]/', $target, $m)) {
                    if (isset($pipe_inodes[$m[1]])) {
                        // Extract pid and fd number
                        preg_match('#/proc/(\d+)/fd/(\d+)#', $fd_path, $pm);
                        $p = $pm[1]; $f = $pm[2];
                        if ($p != getmypid()) {
                            $cmdline = @file_get_contents("/proc/$p/cmdline");
                            $cmdline = str_replace("\0", " ", $cmdline);
                            echo "  PID=$p fd=$f -> $target  CMD: $cmdline\n";
                        }
                    }
                }
            }
        }

        // Kill everything
        proc_terminate($proc, 9);
        break;
    }

    if ($n > 0) {
        // Read only from streams that stream_select marked as ready
        foreach ($r as $ready_stream) {
            $fd_key = array_search($ready_stream, $pipes, true);
            $line = fread($ready_stream, 8192);
            if (strlen($line) == 0) {
                echo "[iter=$iteration] EOF on pipe[$fd_key] after {$t_elapsed}s\n";
                // Close this pipe so stream_select stops monitoring it
                fclose($ready_stream);
                unset($pipes[$fd_key]);
                if (empty($pipes)) {
                    echo "[iter=$iteration] All pipes closed — normal exit\n";
                    break 2;
                }
                continue;
            }
            $data .= $line;
            echo "[iter=$iteration] read " . strlen($line) . " bytes from pipe[$fd_key] ({$t_elapsed}s)\n";
        }
    }
}

echo "\n--- Collected output ---\n";
echo $data;
echo "--- End output ---\n\n";

$stat = proc_get_status($proc);
echo "Final status: running={$stat['running']} exitcode={$stat['exitcode']} termsig={$stat['termsig']}\n";

proc_close($proc);
echo "Done.\n";
