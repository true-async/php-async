--TEST--
ThreadChannel: the cancellation token ends a wait
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--FILE--
<?php

use Async\ThreadChannel;

use function Async\spawn;

// A fired token ends a parked recv() or send() with the exception Channel::recv()
// raises, so one catch (AsyncCancellation) covers both channel classes.
function timed(string $label, callable $body): void
{
    $c = spawn(static function () use ($body, $label): void {
        try {
            $body();
            echo "$label: returned\n";
        } catch (Async\AsyncCancellation $e) {
            echo "$label: " . $e::class . "\n";
        }
    });

    // Watchdog: a wait that did not end on the token shows up as STILL PARKED.
    spawn(static function () use ($c, $label): void {
        Async\delay(1000);
        if (!$c->isCompleted()) {
            echo "$label: STILL PARKED\n";
            $c->cancel();
        }
    });
}

$empty = new ThreadChannel(4);
timed('recv', static fn() => $empty->recv(Async\timeout(100)));

$full = new ThreadChannel(1);
$full->send('taken');
timed('send', static fn() => $full->send('overflow', Async\timeout(100)));

// A token that never fires ends nothing: both calls complete on their own.
$open = new ThreadChannel(4);
$open->send('value', Async\timeout(5000));
var_dump($open->recv(Async\timeout(5000)));

// The token's own exception is chained as previous, as Async\Channel chains it.
$chained = new ThreadChannel(4);

try {
    $chained->recv(Async\timeout(100));
} catch (Async\AsyncCancellation $e) {
    echo "previous: ", $e->getPrevious()?->getMessage() ?? 'NULL', "\n";
}

Async\delay(1200);
echo "Done\n";
?>
--EXPECT--
string(5) "value"
previous: Timeout occurred after 100 milliseconds
recv: Async\OperationCanceledException
send: Async\OperationCanceledException
Done
