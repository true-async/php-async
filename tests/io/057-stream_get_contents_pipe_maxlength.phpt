--TEST--
stream_get_contents on pipe with maxlength
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
        [PHP_BINARY, '-r', 'echo "ABCDEFGHIJKLMNOP";'],
        [1 => ['pipe', 'w']],
        $pipes
    );

    // Read only first 5 bytes
    $data = stream_get_contents($pipes[1], 5);
    echo "First 5: '$data'\n";

    // Read next 3 bytes
    $data = stream_get_contents($pipes[1], 3);
    echo "Next 3: '$data'\n";

    // Read rest
    $data = stream_get_contents($pipes[1]);
    echo "Rest: '$data'\n";

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
First 5: 'ABCDE'
Next 3: 'FGH'
Rest: 'IJKLMNOP'
Result: done
End
