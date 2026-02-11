--TEST--
Character device /dev/null is not misclassified as TTY
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
if (!file_exists('/dev/null')) echo "skip /dev/null not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c = spawn(function() {
    $f = fopen('/dev/null', 'w');
    $written = fwrite($f, "test data that goes nowhere\n");
    fclose($f);
    echo "Written: $written\n";

    $f = fopen('/dev/null', 'r');
    $data = fread($f, 1024);
    fclose($f);
    echo "Read length: " . strlen($data) . "\n";

    return "ok";
});

$result = await($c);
echo "Result: $result\n";

?>
--EXPECT--
Written: 28
Read length: 0
Result: ok
