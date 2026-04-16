--TEST--
ThreadChannel: two channels in opposite directions between same threads
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

$requests = new ThreadChannel(4);
$responses = new ThreadChannel(4);

spawn(function() use ($requests, $responses) {
    $worker = spawn_thread(function() use ($requests, $responses) {
        for ($i = 0; $i < 3; $i++) {
            $req = $requests->recv();
            $responses->send("reply:$req");
        }
    });

    $requests->send("hello");
    $requests->send("world");
    $requests->send("end");

    echo $responses->recv() . "\n";
    echo $responses->recv() . "\n";
    echo $responses->recv() . "\n";

    await($worker);
    echo "Done\n";
});
?>
--EXPECT--
reply:hello
reply:world
reply:end
Done
