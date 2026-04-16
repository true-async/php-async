--TEST--
ThreadChannel: cross-thread 2 sender threads → 2 receiver threads
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

$ch = new ThreadChannel(16);

spawn(function() use ($ch) {
    // 2 senders
    $s1 = spawn_thread(function() use ($ch) {
        for ($i = 0; $i < 5; $i++) {
            $ch->send("S1:$i");
        }
    });

    $s2 = spawn_thread(function() use ($ch) {
        for ($i = 0; $i < 5; $i++) {
            $ch->send("S2:$i");
        }
    });

    // 2 receivers
    $r1 = spawn_thread(function() use ($ch) {
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $ch->recv();
        }
        return $results;
    });

    $r2 = spawn_thread(function() use ($ch) {
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $ch->recv();
        }
        return $results;
    });

    await($s1);
    await($s2);

    $res1 = await($r1);
    $res2 = await($r2);

    $all = array_merge($res1, $res2);
    sort($all);
    echo implode(",", $all) . "\n";
    echo "Count: " . count($all) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
S1:0,S1:1,S1:2,S1:3,S1:4,S2:0,S2:1,S2:2,S2:3,S2:4
Count: 10
Done
