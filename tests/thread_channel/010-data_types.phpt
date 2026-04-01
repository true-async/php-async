--TEST--
ThreadChannel: various data types transfer correctly
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(16);

// Scalars
$ch->send(null);
$ch->send(true);
$ch->send(false);
$ch->send(42);
$ch->send(3.14);
$ch->send("hello world");

// Array
$ch->send(["key" => "value", "nested" => [1, 2, 3]]);

// Verify
var_dump($ch->recv()); // null
var_dump($ch->recv()); // true
var_dump($ch->recv()); // false
var_dump($ch->recv()); // 42
var_dump($ch->recv()); // 3.14
var_dump($ch->recv()); // "hello world"
var_dump($ch->recv()); // array

echo "Done\n";
?>
--EXPECT--
NULL
bool(true)
bool(false)
int(42)
float(3.14)
string(11) "hello world"
array(2) {
  ["key"]=>
  string(5) "value"
  ["nested"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
}
Done
