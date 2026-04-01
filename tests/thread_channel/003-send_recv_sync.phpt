--TEST--
ThreadChannel: synchronous send and recv without coroutines
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(4);

$ch->send(42);
$ch->send("hello");
$ch->send([1, 2, 3]);
$ch->send(true);

echo "Count: " . $ch->count() . "\n";
echo "Full: " . ($ch->isFull() ? "yes" : "no") . "\n";

var_dump($ch->recv());
var_dump($ch->recv());
var_dump($ch->recv());
var_dump($ch->recv());

echo "Empty: " . ($ch->isEmpty() ? "yes" : "no") . "\n";

echo "Done\n";
?>
--EXPECT--
Count: 4
Full: yes
int(42)
string(5) "hello"
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
bool(true)
Empty: yes
Done
