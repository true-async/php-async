--TEST--
Large data transfer through pipes in coroutines
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

$size = 64 * 1024;
$payload = str_repeat('X', $size);

$writer = spawn(function() use ($pipes, $payload) {
    $written = 0;
    $len = strlen($payload);
    while ($written < $len) {
        $chunk = substr($payload, $written, 8192);
        $result = fwrite($pipes[0], $chunk);
        if ($result === false || $result === 0) {
            break;
        }
        $written += $result;
    }
    fclose($pipes[0]);
    return $written;
});

$reader = spawn(function() use ($pipes) {
    $received = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $received .= $chunk;
    }
    fclose($pipes[1]);
    return strlen($received);
});

[$results, $exceptions] = await_all([$writer, $reader]);

$written = $results[0];
$read = $results[1];

echo "Written: $written\n";
echo "Read: $read\n";
echo "Match: " . ($written === $read ? "yes" : "no") . "\n";

proc_close($process);
echo "End\n";

?>
--EXPECT--
Start
Written: 65536
Read: 65536
Match: yes
End
