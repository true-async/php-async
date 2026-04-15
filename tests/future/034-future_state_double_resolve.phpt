--TEST--
FutureState: double complete()/error() raises AsyncError with original location
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

// Covers future.c FUTURE_STATE_METHOD(complete)/error() "already completed" branches
// at 913-922 and 962-971 — the reported-location string is generated from
// completed_filename/lineno.

$coroutine = spawn(function() {
    // 1. Double complete()
    $state = new FutureState();
    $future = new Future($state);
    $state->complete(1);
    try {
        $state->complete(2);
    } catch (\Async\AsyncException $e) {
        echo "double complete: ", preg_replace('/\d+$/', 'N', $e->getMessage()), "\n";
    }
    $future->ignore();

    // 2. error() after complete()
    $state2 = new FutureState();
    $future2 = new Future($state2);
    $state2->complete(1);
    try {
        $state2->error(new \RuntimeException("x"));
    } catch (\Async\AsyncException $e) {
        echo "error-after-complete: ", preg_replace('/\d+$/', 'N', $e->getMessage()), "\n";
    }
    $future2->ignore();

    // 3. complete() after error()
    $state3 = new FutureState();
    $future3 = new Future($state3);
    $state3->error(new \RuntimeException("x"));
    try {
        $state3->complete(1);
    } catch (\Async\AsyncException $e) {
        echo "complete-after-error: ", preg_replace('/\d+$/', 'N', $e->getMessage()), "\n";
    }
    $future3->ignore();
});

await($coroutine);

?>
--EXPECTF--
double complete: FutureState is already completed at %s.php:N
error-after-complete: FutureState is already completed at %s.php:N
complete-after-error: FutureState is already completed at %s.php:N
