--TEST--
Reading STDIN in coroutine does not block other coroutines
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

// Child script: read STDIN in one coroutine while another coroutine works independently
$code = <<<'CHILD'
use function Async\spawn;
use function Async\await_all;

$reader = spawn(function() {
    $data = '';
    while (!feof(STDIN)) {
        $chunk = fread(STDIN, 1024);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $data .= $chunk;
    }
    return $data;
});

$worker = spawn(function() {
    $sum = 0;
    for ($i = 0; $i < 5; $i++) {
        $sum += $i;
    }
    return "worker:$sum";
});

[$results, $exceptions] = await_all([$reader, $worker]);

echo "Reader: " . $results[0] . "\n";
echo "Worker: " . $results[1] . "\n";
echo "Done\n";
CHILD;

$process = proc_open(
    [$php, "-r", $code],
    [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ],
    $pipes
);

if (!is_resource($process)) {
    die("Failed to create process");
}

// Send data to child's STDIN, then close
fwrite($pipes[0], "hello from parent");
fclose($pipes[0]);

// Read child's output
$output = '';
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 1024);
    if ($chunk === '' || $chunk === false) {
        break;
    }
    $output .= $chunk;
}
fclose($pipes[1]);

$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);

$exit = proc_close($process);

echo $output;
if ($stderr !== '') {
    echo "STDERR: $stderr\n";
}
echo "Exit: $exit\n";
echo "End\n";

?>
--EXPECT--
Start
Reader: hello from parent
Worker: worker:10
Done
Exit: 0
End
