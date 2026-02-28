--TEST--
exec() async preserves non-zero exit codes
--SKIPIF--
<?php
if (!function_exists("exec")) echo "skip exec() is not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$future = spawn(function () {
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }

    // Test exit code 0
    $output = [];
    $return_var = null;
    exec($php . ' -r "exit(0);"', $output, $return_var);
    echo "exit(0): $return_var\n";

    // Test exit code 1
    $output = [];
    $return_var = null;
    exec($php . ' -r "exit(1);"', $output, $return_var);
    echo "exit(1): $return_var\n";

    // Test exit code 2
    $output = [];
    $return_var = null;
    exec($php . ' -r "exit(2);"', $output, $return_var);
    echo "exit(2): $return_var\n";

    // Test exit code 42
    $output = [];
    $return_var = null;
    exec($php . ' -r "exit(42);"', $output, $return_var);
    echo "exit(42): $return_var\n";

    // Test exit code 255
    $output = [];
    $return_var = null;
    exec($php . ' -r "exit(255);"', $output, $return_var);
    echo "exit(255): $return_var\n";

    // Test with output AND non-zero exit code
    $output = [];
    $return_var = null;
    exec($php . ' -r "echo \"hello\"; exit(7);"', $output, $return_var);
    echo "output+exit(7): " . implode("", $output) . " code=$return_var\n";

    echo "done\n";
});

await($future);
?>
--EXPECT--
exit(0): 0
exit(1): 1
exit(2): 2
exit(42): 42
exit(255): 255
output+exit(7): hello code=7
done
