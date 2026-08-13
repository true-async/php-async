--TEST--
ThreadChannel: a wake that is not the token parks the call again
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--FILE--
<?php

use Async\ThreadChannel;

use function Async\spawn;

// One freed slot wakes every parked sender and one value wakes every parked
// receiver, but only one can proceed. The losers were not cancelled: treating the
// wake as a cancellation would end them with no value and no exception.
$full = new ThreadChannel(1);
$full->send('taken');

$sent = [];

foreach (['b', 'c'] as $value) {
    spawn(static function () use ($full, $value, &$sent): void {
        $full->send($value, Async\timeout(5000));
        $sent[] = $value;
    });
}

Async\delay(50);
$taken = [$full->recv()];   // one free slot, both senders woken
Async\delay(50);
$taken[] = $full->recv();
Async\delay(50);
$taken[] = $full->recv();

\sort($sent);
\sort($taken);
echo "sent: ", \implode(',', $sent), "\n";
echo "taken: ", \implode(',', $taken), "\n";

$empty = new ThreadChannel(4);
$received = [];

foreach (['x', 'y'] as $label) {
    spawn(static function () use ($empty, &$received): void {
        $received[] = $empty->recv(Async\timeout(5000));
    });
}

Async\delay(50);
$empty->send('one');        // one value, both receivers woken
Async\delay(50);
$empty->send('two');
Async\delay(50);

\sort($received);
echo "received: ", \implode(',', $received), "\n";
echo "Done\n";
?>
--EXPECT--
sent: b,c
taken: b,c,taken
received: one,two
Done
