--TEST--
Channel: foreach propagates a deadlock instead of reporting a clean end of stream
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

// Only an explicit close() is a normal end of iteration. A deadlock arrives as the same
// ChannelException, so swallowing it would silently turn a bug into "the stream ended, 0 items".
$result = await(spawn(function () {
    $channel = new Async\Channel(capacity: 2, noProducerTimeout: 100, hardTimeouts: true);

    try {
        foreach ($channel as $value) {
            echo "unexpected value: $value\n";
        }

        return 'loop ended silently';
    } catch (Async\ChannelException $exception) {
        return 'propagated: ' . $exception->reason->name;
    }
}));

echo $result, "\n";
echo "done\n";
?>
--EXPECT--
start
propagated: NO_PRODUCERS
done
