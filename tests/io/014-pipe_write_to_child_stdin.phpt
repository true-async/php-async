--TEST--
Write to child process stdin pipe and read stdout
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

    $process = proc_open(
        [$php, "-r", "echo strtoupper(fgets(STDIN));"],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        echo "Failed to create process\n";
        return "fail";
    }

    // Write to child stdin
    fwrite($pipes[0], "hello async world\n");
    fclose($pipes[0]);

    // Read from child stdout
    $output = fread($pipes[1], 1024);
    echo "Output: '$output'\n";

    fclose($pipes[1]);
    $exit = proc_close($process);
    echo "Exit: $exit\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
Output: 'HELLO ASYNC WORLD
'
Exit: 0
Result: done
End
