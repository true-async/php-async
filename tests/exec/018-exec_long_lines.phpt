--TEST--
exec() async handles lines exceeding 8KB buffer
--SKIPIF--
<?php
if (!function_exists("exec")) echo "skip exec() is not available";
?>
--FILE--
<?php

use function Async\spawn;

spawn(function () {
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }

    $output = [];
    $return_var = null;

    // Generate a line longer than the 8KB initial buffer (10000 chars)
    $code = 'echo str_repeat("A", 10000) . "\n" . str_repeat("B", 10000) . "\n" . "short\n";';
    exec($php . ' -r ' . escapeshellarg($code), $output, $return_var);

    echo "Count: " . count($output) . "\n";
    echo "Line0 len: " . strlen($output[0]) . "\n";
    echo "Line0 first: " . $output[0][0] . "\n";
    echo "Line0 last: " . $output[0][strlen($output[0]) - 1] . "\n";
    echo "Line0 uniform: " . (trim($output[0], 'A') === '' ? 'yes' : 'no') . "\n";
    echo "Line1 len: " . strlen($output[1]) . "\n";
    echo "Line1 uniform: " . (trim($output[1], 'B') === '' ? 'yes' : 'no') . "\n";
    echo "Line2: \"$output[2]\"\n";
    echo "Return: $return_var\n";
});
?>
--EXPECT--
Count: 3
Line0 len: 10000
Line0 first: A
Line0 last: A
Line0 uniform: yes
Line1 len: 10000
Line1 uniform: yes
Line2: "short"
Return: 0
