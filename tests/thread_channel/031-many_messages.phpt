--TEST--
ThreadChannel: 1000 messages — stress test for race conditions
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
$count = 1000;

spawn(function() use ($ch, $count) {
    $thread = spawn_thread(function() use ($ch, $count) {
        for ($i = 0; $i < $count; $i++) {
            $ch->send($i);
        }
    });

    $sum = 0;
    for ($i = 0; $i < $count; $i++) {
        $sum += $ch->recv();
    }

    await($thread);

    $expected = ($count - 1) * $count / 2;
    echo "Sum: $sum\n";
    echo "Expected: $expected\n";
    echo "Match: " . ($sum === $expected ? "yes" : "no") . "\n";
    echo "Done\n";
});
?>
--EXPECT--
Sum: 499500
Expected: 499500
Match: yes
Done
