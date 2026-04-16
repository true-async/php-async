--TEST--
ThreadChannel: FIFO ordering preserved
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(8);

for ($i = 0; $i < 8; $i++) {
    $ch->send($i);
}

$results = [];
for ($i = 0; $i < 8; $i++) {
    $results[] = $ch->recv();
}

echo implode(",", $results) . "\n";
echo "Done\n";
?>
--EXPECT--
0,1,2,3,4,5,6,7
Done
