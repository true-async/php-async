--TEST--
ThreadChannel: send non-transferable value (resource) throws exception
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(4);

try {
    $ch->send(tmpfile());
    echo "ERROR: should have thrown\n";
} catch (\Error $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Caught: Cannot transfer a resource between threads
Done
