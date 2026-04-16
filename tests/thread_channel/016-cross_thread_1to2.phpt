--TEST--
ThreadChannel: cross-thread 1 sender (main) → 2 receiver threads
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
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $ch->recv();
        }
        return $results;
    });

    $t2 = spawn_thread(function() use ($ch) {
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $ch->recv();
        }
        return $results;
    });

    // Send 6 values from main
    for ($i = 0; $i < 6; $i++) {
        $ch->send($i);
    }

    $r1 = await($t1);
    $r2 = await($t2);

    $all = array_merge($r1, $r2);
    sort($all);
    echo implode(",", $all) . "\n";
    echo "Count: " . count($all) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
0,1,2,3,4,5
Count: 6
Done
