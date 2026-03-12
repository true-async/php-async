--TEST--
exec() async handles CRLF line endings
--SKIPIF--
<?php
if (!function_exists("exec")) echo "skip exec() is not available";
// JIT + --repeat causes heap-use-after-free in zend_jit_rope_end (not async-specific)
if (ini_get("opcache.jit") && ini_get("opcache.jit") !== "0" && ini_get("opcache.jit") !== "off") echo "skip JIT rope_end UAF with --repeat";
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

    // Emit lines with \r\n (Windows-style)
    exec($php . ' -r "echo \"line1\\r\\nline2\\r\\nline3\\r\\n\";"', $output, $return_var);

    echo "Count: " . count($output) . "\n";
    foreach ($output as $i => $line) {
        echo "[$i]: \"$line\"\n";
    }
    echo "Return: $return_var\n";
});
?>
--EXPECT--
Count: 3
[0]: "line1"
[1]: "line2"
[2]: "line3"
Return: 0
