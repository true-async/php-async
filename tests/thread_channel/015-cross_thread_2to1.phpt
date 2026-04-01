--TEST--
ThreadChannel: cross-thread 2 sender threads → 1 receiver (main)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$ch = new ThreadChannel(8);

spawn(function() use ($ch) {
    $t1 = spawn_thread(function() use ($ch) {
        for ($i = 0; $i < 3; $i++) {
            $ch->send("A$i");
        }
    });

    $t2 = spawn_thread(function() use ($ch) {
        for ($i = 0; $i < 3; $i++) {
            $ch->send("B$i");
        }
    });

    // Receive all 6 values
    $results = [];
    for ($i = 0; $i < 6; $i++) {
        $results[] = $ch->recv();
    }

    await($t1);
    await($t2);

    sort($results);
    echo implode(",", $results) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
A0,A1,A2,B0,B1,B2
Done
