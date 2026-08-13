--TEST--
ThreadChannel: a refused value never reaches the buffer
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--FILE--
<?php

use Async\ThreadChannel;

// A resource cannot cross a thread boundary: send() throws and leaves the channel
// as it was, because a receiver cannot tell an undefined slot from a message.
$ch = new ThreadChannel(4);

try {
    $ch->send(fopen('php://memory', 'r'));
} catch (Error $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "count: " . $ch->count() . "\n";
echo "empty: " . var_export($ch->isEmpty(), true) . "\n";

// The channel stays usable for a value that can be transferred.
$ch->send(['ok' => 1]);
var_dump($ch->recv());

echo "Done\n";
?>
--EXPECT--
Caught: Cannot transfer a resource between threads
count: 0
empty: true
array(1) {
  ["ok"]=>
  int(1)
}
Done
