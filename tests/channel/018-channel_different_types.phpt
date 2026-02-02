--TEST--
Channel: send and recv different types
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(10);

// Send various types
$ch->send(null);
$ch->send(true);
$ch->send(42);
$ch->send(3.14);
$ch->send("string");
$ch->send([1, 2, 3]);
$ch->send(new stdClass());
$ch->send(function() { return "closure"; });

spawn(function() use ($ch) {
    echo "null: " . var_export($ch->recv(), true) . "\n";
    echo "bool: " . var_export($ch->recv(), true) . "\n";
    echo "int: " . var_export($ch->recv(), true) . "\n";
    echo "float: " . var_export($ch->recv(), true) . "\n";
    echo "string: " . var_export($ch->recv(), true) . "\n";
    echo "array: " . var_export($ch->recv(), true) . "\n";
    echo "object: " . get_class($ch->recv()) . "\n";
    $fn = $ch->recv();
    echo "closure: " . $fn() . "\n";
});

echo "Done\n";
?>
--EXPECT--
Done
null: NULL
bool: true
int: 42
float: 3.14
string: 'string'
array: array (
  0 => 1,
  1 => 2,
  2 => 3,
)
object: stdClass
closure: closure
