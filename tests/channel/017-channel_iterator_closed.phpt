--TEST--
Channel: iterator on closed channel with data
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(3);
$ch->send(1);
$ch->send(2);
$ch->send(3);
$ch->close();

spawn(function() use ($ch) {
    $values = [];
    foreach ($ch as $value) {
        $values[] = $value;
    }
    echo "Received: " . implode(", ", $values) . "\n";
    echo "Count after iteration: " . count($ch) . "\n";
});

echo "Done\n";
?>
--EXPECT--
Done
Received: 1, 2, 3
Count after iteration: 0
