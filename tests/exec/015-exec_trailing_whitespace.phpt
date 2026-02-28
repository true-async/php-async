--TEST--
exec() async strips trailing whitespace from lines
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

    // Lines with trailing spaces, tabs, \r, and mixed
    exec($php . ' -r "echo \"trailing_space   \\ntrailing_tab\\t\\t\\ntrailing_cr\\r\\nmixed \\t\\r\\nno_trailing\\n\";"', $output, $return_var);

    echo "Count: " . count($output) . "\n";
    foreach ($output as $i => $line) {
        echo "[$i]: \"$line\"\n";
    }
    echo "Return: $return_var\n";
});
?>
--EXPECT--
Count: 5
[0]: "trailing_space"
[1]: "trailing_tab"
[2]: "trailing_cr"
[3]: "mixed"
[4]: "no_trailing"
Return: 0
