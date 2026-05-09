--TEST--
await_all() — already-failed Coroutine as a trigger does not crash the awaiter
--DESCRIPTION--
Companion regression test to 094: same crash class, but the failing trigger is
a Coroutine that threw rather than a Future that errored. The synchronous
REPLAY of the closed coroutine event used to dereference a NULL coroutine
pointer in async_waiting_callback's ignore_errors branch.

await_all() (with ignore_errors=true under the hood) returns [results, errors]
without throwing. The throwing coroutine must show up in the errors slot.
--FILE--
<?php

use Async\Future;
use Async\FutureState;
use function Async\spawn;
use function Async\await_all;

echo "start\n";

$st = new FutureState();
$f = new Future($st);
spawn(fn() => $st->complete(1));
$bad = spawn(fn() => throw new RuntimeException("bad"));

$awaiter = spawn(function() use ($f, $bad) {
    [$results, $errors] = await_all([$f, $bad], null, true, true);
    echo "results=" . count($results) . " errors=" . count($errors) . "\n";
    foreach ($errors as $key => $err) {
        echo "err[$key]: " . $err::class . " " . $err->getMessage() . "\n";
    }
});

// Including $bad in the main awaiter list flags its exception as handled at
// the top-level scope so the per-coroutine-finish unhandled-exception path
// does not fire before $awaiter gets a chance to consume it via await_all.
await_all([$awaiter, $bad], null, true, true);
echo "end\n";
?>
--EXPECT--
start
results=2 errors=1
err[1]: RuntimeException bad
end
