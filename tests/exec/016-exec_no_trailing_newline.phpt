--TEST--
exec() async handles output without trailing newline
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

    // Test 1: single line, no trailing newline
    $output1 = [];
    exec($php . ' -r "echo \"no_newline\";"', $output1, $rc1);
    echo "Test1 count: " . count($output1) . "\n";
    echo "Test1 [0]: \"$output1[0]\"\n";
    echo "Test1 return: \"" . exec($php . ' -r "echo \"last_line\";"') . "\"\n";

    // Test 2: multiple lines, last line has no trailing newline
    $output2 = [];
    exec($php . ' -r "echo \"first\\nsecond\\nthird_no_nl\";"', $output2, $rc2);
    echo "Test2 count: " . count($output2) . "\n";
    foreach ($output2 as $i => $line) {
        echo "Test2 [$i]: \"$line\"\n";
    }

    // Test 3: completely empty output
    $output3 = [];
    $ret3 = exec($php . ' -r "/* no output */;"', $output3, $rc3);
    echo "Test3 count: " . count($output3) . "\n";
    echo "Test3 return: \"$ret3\"\n";
    echo "Test3 rc: $rc3\n";
});
?>
--EXPECT--
Test1 count: 1
Test1 [0]: "no_newline"
Test1 return: "last_line"
Test2 count: 3
Test2 [0]: "first"
Test2 [1]: "second"
Test2 [2]: "third_no_nl"
Test3 count: 0
Test3 return: ""
Test3 rc: 0
