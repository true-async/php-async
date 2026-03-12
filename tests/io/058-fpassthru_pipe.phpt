--TEST--
fpassthru reads remaining pipe data to stdout
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
    $process = proc_open(
        [PHP_BINARY, '-r', 'echo "Hello World";'],
        [1 => ['pipe', 'w']],
        $pipes
    );

    // Read first 6 bytes
    $partial = fread($pipes[1], 6);
    echo "Partial: '$partial'\n";

    // fpassthru sends the rest to stdout
    echo "Rest: ";
    $bytes = fpassthru($pipes[1]);
    echo "\n";
    echo "Bytes passed: $bytes\n";

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
Partial: 'Hello '
Rest: World
Bytes passed: 5
Result: done
End
