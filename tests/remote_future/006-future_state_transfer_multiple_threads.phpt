--TEST--
RemoteFuture: FutureState transferred — first thread to complete wins
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    // Both threads share the same FutureState — first to complete wins
    $t1 = spawn_thread(function() use ($state) {
        $state->complete("from t1");
    });

    $t2 = spawn_thread(function() use ($state) {
        // May or may not throw depending on timing
        try {
            $state->complete("from t2");
        } catch (\Error $e) {
            // expected — already completed by t1
        }
    });

    $result = await($future);
    echo "Result starts with 'from t': " . (str_starts_with($result, "from t") ? "yes" : "no") . "\n";

    await($t1);
    await($t2);
    echo "Done\n";
});
?>
--EXPECT--
Result starts with 'from t': yes
Done
