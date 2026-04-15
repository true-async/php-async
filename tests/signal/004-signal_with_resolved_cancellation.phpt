--TEST--
Async\signal(): an already-resolved cancellation argument returns an immediately-rejected Future
--FILE--
<?php

use Async\Signal;
use Async\FutureState;
use Async\Future;
use function Async\signal;
use function Async\await;

// Covers async.c:1166-1189 — Async_signal() fast-path when the supplied
// cancellation event is already CLOSED. Existing 003-signal_already_cancelled
// uses a Timeout that does not actually transition to CLOSED, so this branch
// was never exercised before.

echo "start\n";

$state = new FutureState();
$state->complete("ready");

$cancel = new Future($state);
$cancel->ignore();

$result = signal(Signal::SIGINT, $cancel);

try {
    await($result);
    echo "no exception\n";
} catch (\Async\AsyncCancellation $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
caught: Signal wait cancelled
end
