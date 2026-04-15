--TEST--
Future::finally() - registering on an already-completed/rejected future spawns mapper immediately
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\Future;

// Covers future.c async_future_create_mapper() already-completed branch
// (L1672-1697): when source future is completed, a coroutine is spawned
// immediately to process the mapper. Exercised via Future::finally() on
// Future::completed() and Future::failed() helpers.

$coroutine = spawn(function() {
    // 1. finally() on an already-successful future
    $ok = Future::completed(10);
    $r1 = $ok->finally(function() {
        echo "finally-ok\n";
    });
    var_dump(await($r1));

    // 2. finally() on an already-failed future
    $bad = Future::failed(new \RuntimeException("nope"));
    $r2 = $bad->finally(function() {
        echo "finally-err\n";
    });
    try {
        await($r2);
    } catch (\RuntimeException $e) {
        echo "caught: ", $e->getMessage(), "\n";
    }
});

await($coroutine);

?>
--EXPECT--
finally-ok
int(10)
finally-err
caught: nope
