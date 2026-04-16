--TEST--
ThreadChannel: double close does not crash
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(4);
$ch->send("data");
$ch->close();

echo "Closed once\n";

$ch->close();

echo "Closed twice\n";
echo "isClosed: " . ($ch->isClosed() ? "yes" : "no") . "\n";
echo "Done\n";
?>
--EXPECT--
Closed once
Closed twice
isClosed: yes
Done
