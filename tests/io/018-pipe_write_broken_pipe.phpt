--TEST--
Write to pipe after reader closed handles error gracefully
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

    // Child exits immediately without reading stdin
    $process = proc_open(
        [$php, "-r", "exit(0);"],
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

    // Wait for child to exit
    $output = fread($pipes[1], 1024);
    fclose($pipes[1]);

    // Now try writing to stdin of dead process — should not fatal
    $result = @fwrite($pipes[0], str_repeat("X", 4096));
    echo "Write to dead pipe: " . var_export($result, true) . "\n";

    fclose($pipes[0]);
    proc_close($process);

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECTF--
Start
Write to dead pipe: %s
Result: done
End
