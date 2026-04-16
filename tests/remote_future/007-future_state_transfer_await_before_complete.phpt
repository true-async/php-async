--TEST--
RemoteFuture: main awaits before thread completes — proper suspend/resume
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
use function Async\delay;

spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $thread = spawn_thread(function() use ($state) {
        // Simulate work — thread takes some time
        $sum = 0;
        for ($i = 0; $i < 100000; $i++) {
            $sum += $i;
        }
        $state->complete($sum);
    });

    // Main thread awaits — will suspend until thread completes
    $result = await($future);
    echo "Result: $result\n";
    echo "Expected: " . (99999 * 100000 / 2) . "\n";
    echo "Match: " . ($result === 99999 * 100000 / 2 ? "yes" : "no") . "\n";

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
Result: 4999950000
Expected: 4999950000
Match: yes
Done
