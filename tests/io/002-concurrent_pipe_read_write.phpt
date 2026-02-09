--TEST--
Concurrent pipe read/write between coroutines
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

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

$writer = spawn(function() use ($pipes) {
    fwrite($pipes[0], "message-one");
    fwrite($pipes[0], "|message-two");
    fwrite($pipes[0], "|message-three");
    fclose($pipes[0]);
    return "writer done";
});

$reader = spawn(function() use ($pipes) {
    $all = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1024);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $all .= $chunk;
    }
    fclose($pipes[1]);
    return $all;
});

[$results, $exceptions] = await_all([$writer, $reader]);

echo "Writer: " . $results[0] . "\n";
echo "Reader: " . $results[1] . "\n";

proc_close($process);
echo "End\n";

?>
--EXPECT--
Start
Writer: writer done
Reader: message-one|message-two|message-three
End
