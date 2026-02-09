--TEST--
Large bidirectional pipe IO with proc_open in async context
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }

    // Child reads stdin, reverses each line, writes to stdout
    $code = 'while (($line = fgets(STDIN)) !== false) { echo strrev(rtrim($line, "\n")) . "\n"; }';

    $process = proc_open(
        [$php, "-r", $code],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // Write multiple lines
    fwrite($pipes[0], "hello\n");
    fwrite($pipes[0], "world\n");
    fwrite($pipes[0], "async\n");
    fclose($pipes[0]);

    // Read all output
    $output = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1024);
        if ($chunk === '' || $chunk === false) break;
        $output .= $chunk;
    }
    fclose($pipes[1]);

    $exit = proc_close($process);
    echo "Output: " . rtrim($output) . "\n";
    echo "Exit: $exit\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
Output: olleh
dlrow
cnysa
Exit: 0
Result: done
End
