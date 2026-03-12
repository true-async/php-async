--TEST--
exec() async handles UTF-8 and special characters
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

    // Test 1: UTF-8 multibyte characters
    $output1 = [];
    exec($php . ' -r "echo \"Привет\\nこんにちは\\n🚀🎉\\n\";"', $output1, $rc1);
    echo "UTF8 count: " . count($output1) . "\n";
    foreach ($output1 as $i => $line) {
        echo "UTF8 [$i]: \"$line\"\n";
    }

    // Test 2: leading whitespace is preserved (only trailing is stripped)
    $output2 = [];
    exec($php . ' -r "echo \"  leading_spaces\\n\\tleading_tab\\n\";"', $output2, $rc2);
    echo "Leading count: " . count($output2) . "\n";
    foreach ($output2 as $i => $line) {
        echo "Leading [$i]: \"$line\"\n";
    }

    // Test 3: embedded tabs are preserved, only trailing tabs stripped
    $output3 = [];
    exec($php . ' -r "echo \"a\\tb\\tc\\n\";"', $output3, $rc3);
    echo "Embedded tabs: \"$output3[0]\"\n";
    echo "Embedded tabs len: " . strlen($output3[0]) . "\n";
});
?>
--EXPECT--
UTF8 count: 3
UTF8 [0]: "Привет"
UTF8 [1]: "こんにちは"
UTF8 [2]: "🚀🎉"
Leading count: 2
Leading [0]: "  leading_spaces"
Leading [1]: "	leading_tab"
Embedded tabs: "a	b	c"
Embedded tabs len: 5
