--TEST--
Channel: foreach as the first async operation starts the scheduler instead of suspending a NULL coroutine
--FILE--
<?php

echo "start\n";

// foreach() reaches ce->get_iterator straight from FE_RESET, bypassing recv()'s ENSURE_COROUTINE_CONTEXT.
// Iterating here is the very first async operation, so there is no main coroutine yet: the iterator used
// to park on the channel with a NULL coroutine and segfault. The timeout is what makes the wait end.
$channel = new Async\Channel(capacity: 1, noProducerTimeout: 50, hardTimeouts: true);

try {
    foreach ($channel as $value) {
        echo "unexpected value: $value\n";
    }

    echo "loop ended silently\n";
} catch (Async\ChannelException $exception) {
    echo "caught: ", $exception->reason->name, "\n";
}

echo "done\n";
?>
--EXPECT--
start
caught: NO_PRODUCERS
done
