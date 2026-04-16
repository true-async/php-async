--TEST--
Channel: foreach on unbuffered (rendezvous) channel reads directly from the rendezvous slot
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\suspend;

// Covers channel.c channel_iterator_move_forward() L471-475 —
// the unbuffered-channel branch that reads from channel->rendezvous_value
// and clears the rendezvous slot. Existing test 008 uses a buffered
// channel and exercises the zval_circular_buffer_pop branch instead.

$ch = new Channel(0); // capacity 0 = unbuffered / rendezvous

spawn(function() use ($ch) {
    foreach (['x', 'y', 'z'] as $v) {
        $ch->send($v);
    }
    $ch->close();
});

spawn(function() use ($ch) {
    foreach ($ch as $value) {
        echo "got $value\n";
    }
    echo "done\n";
});

?>
--EXPECT--
got x
got y
got z
done
