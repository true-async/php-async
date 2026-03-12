--TEST--
shell_exec() async preserves whitespace in output
--SKIPIF--
<?php
if (!function_exists("shell_exec")) echo "skip shell_exec() is not available";
?>
--FILE--
<?php

use function Async\spawn;

spawn(function () {
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }

    // shell_exec() returns output with \r\n → \n conversion on Windows (text mode),
    // matching the behavior of standard PHP popen().
    $raw = shell_exec($php . ' -r "echo \"line1\\nline2\\n  spaces  \\n\";"');

    echo "Length: " . strlen($raw) . "\n";
    echo "Lines: " . substr_count($raw, "\n") . "\n";

    // Trailing spaces should NOT be stripped (shell_exec is raw)
    $lines = explode("\n", $raw);
    echo "Line3 raw: \"" . $lines[2] . "\"\n";
});
?>
--EXPECT--
Length: 23
Lines: 3
Line3 raw: "  spaces  "
