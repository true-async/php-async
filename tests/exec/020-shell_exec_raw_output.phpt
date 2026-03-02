--TEST--
shell_exec() async preserves raw output including whitespace
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

    // shell_exec() returns raw output without any line processing
    $raw = shell_exec($php . ' -r "echo \"line1\\r\\nline2\\n  spaces  \\n\";"');

    // Verify raw bytes are preserved
    echo "Length: " . strlen($raw) . "\n";
    echo "Has CR: " . (strpos($raw, "\r") !== false ? "yes" : "no") . "\n";
    echo "Lines: " . substr_count($raw, "\n") . "\n";

    // Trailing spaces should NOT be stripped (shell_exec is raw)
    $lines = explode("\n", $raw);
    echo "Line3 raw: \"" . $lines[2] . "\"\n";
});
?>
--EXPECT--
Length: 24
Has CR: yes
Lines: 3
Line3 raw: "  spaces  "
