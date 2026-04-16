--TEST--
ThreadChannel: channel survives after variable goes out of scope — refcount protects
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

spawn(function() {
    $thread = (function() {
        $ch = new ThreadChannel(4);

        $thread = spawn_thread(function() use ($ch) {
            $ch->send("from_thread");
            return $ch->recv();
        });

        $ch->send("from_main");
        echo $ch->recv() . "\n";

        // $ch goes out of scope here, but thread still has a reference
        return $thread;
    })();

    echo await($thread) . "\n";
    echo "Done\n";
});
?>
--EXPECTF--
from_%s
from_%s
Done
