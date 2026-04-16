--TEST--
ThreadChannel: send blocks when buffer full, recv from thread unblocks it
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

// Capacity 2 — third send will block
$ch = new ThreadChannel(2);

spawn(function() use ($ch) {
    $thread = spawn_thread(function() use ($ch) {
        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = $ch->recv();
        }
        return $results;
    });

    // First two fill the buffer, third and fourth will block until thread recvs
    $ch->send("a");
    $ch->send("b");
    $ch->send("c");
    $ch->send("d");
    echo "All sent\n";

    $results = await($thread);
    echo implode(",", $results) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
All sent
a,b,c,d
Done
