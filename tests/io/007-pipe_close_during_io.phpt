--TEST--
Pipe close during IO and error handling
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

    // Child echoes stdin back, then exits when stdin closes
    $process = proc_open(
        [$php, "-r", "echo stream_get_contents(STDIN);"],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // Write data and close the writer pipe
    fwrite($pipes[0], "hello");
    fclose($pipes[0]);

    // Read the echoed data
    $data = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1024);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $data .= $chunk;
    }
    echo "Read data: '$data'\n";
    echo "EOF: " . (feof($pipes[1]) ? "yes" : "no") . "\n";

    fclose($pipes[1]);
    proc_close($process);
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
Read data: 'hello'
EOF: yes
Result: done
End
