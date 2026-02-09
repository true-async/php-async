--TEST--
Concurrent file and pipe IO operations
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) {
    die("skip no php executable defined");
}

$process = proc_open(
    [$php, "-r", "echo stream_get_contents(STDIN);"],
    [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
    ],
    $pipes
);

if (!is_resource($process)) {
    die("Failed to create process");
}

$output = [];

$file_worker = spawn(function() use ($tmpfile, &$output) {
    $fp = fopen($tmpfile, 'w');
    fwrite($fp, "file data from coroutine");
    fclose($fp);

    $fp = fopen($tmpfile, 'r');
    $data = fread($fp, 1024);
    fclose($fp);

    $output[] = "File: '$data'";
    return "file done";
});

$pipe_worker = spawn(function() use ($pipes, &$output) {
    fwrite($pipes[0], "pipe data from coroutine");
    fclose($pipes[0]);

    $data = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1024);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $data .= $chunk;
    }
    fclose($pipes[1]);

    $output[] = "Pipe: '$data'";
    return "pipe done";
});

[$results, $exceptions] = await_all([$file_worker, $pipe_worker]);

sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

echo "Results: " . $results[0] . ", " . $results[1] . "\n";

proc_close($process);
unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
File: 'file data from coroutine'
Pipe: 'pipe data from coroutine'
Results: file done, pipe done
End
