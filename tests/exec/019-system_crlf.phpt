--TEST--
system() async handles CRLF and returns correct last line
--SKIPIF--
<?php
if (!function_exists("system")) echo "skip system() is not available";
?>
--FILE--
<?php

use function Async\spawn;

spawn(function () {
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }

    // Test 1: system() with \r\n line endings — output is printed raw, return value stripped
    echo "=== CRLF ===\n";
    $last = system($php . ' -r "echo \"alpha\\r\\nbeta\\r\\ngamma\\r\\n\";"', $rc);
    // Child output ends with \r\n, so cursor is on a new line already
    echo "Last: \"$last\"\n";
    echo "RC: $rc\n";

    // Test 2: system() with no trailing newline on last line
    echo "=== No trailing newline ===\n";
    $last2 = system($php . ' -r "echo \"one\\ntwo\";"', $rc2);
    // Child output does NOT end with \n, so we need \n before our echo
    echo "\nLast: \"$last2\"\n";
    echo "RC: $rc2\n";
});
?>
--EXPECT--
=== CRLF ===
alpha
beta
gamma
Last: "gamma"
RC: 0
=== No trailing newline ===
one
two
Last: "two"
RC: 0
