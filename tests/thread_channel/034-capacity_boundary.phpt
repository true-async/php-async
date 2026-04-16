--TEST--
ThreadChannel: exactly capacity messages — buffer full but no blocking
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

$capacity = 5;
$ch = new ThreadChannel($capacity);

spawn(function() use ($ch, $capacity) {
    $thread = spawn_thread(function() use ($ch, $capacity) {
        // Send exactly capacity messages — should not block
        for ($i = 0; $i < $capacity; $i++) {
            $ch->send($i);
        }
        return "sent_all";
    });

    $result = await($thread);
    echo "Thread: $result\n";

    echo "isFull: " . ($ch->isFull() ? "yes" : "no") . "\n";
    echo "count: " . $ch->count() . "\n";

    $results = [];
    for ($i = 0; $i < $capacity; $i++) {
        $results[] = $ch->recv();
    }
    echo implode(" ", $results) . "\n";

    echo "isEmpty: " . ($ch->isEmpty() ? "yes" : "no") . "\n";
    echo "Done\n";
});
?>
--EXPECT--
Thread: sent_all
isFull: yes
count: 5
0 1 2 3 4
isEmpty: yes
Done
