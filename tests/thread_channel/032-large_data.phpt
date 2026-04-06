--TEST--
ThreadChannel: large string and array transfer
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

$ch = new ThreadChannel(4);

spawn(function() use ($ch) {
    $thread = spawn_thread(function() use ($ch) {
        // Large string (1MB)
        $ch->send(str_repeat("X", 1024 * 1024));

        // Large array
        $arr = [];
        for ($i = 0; $i < 1000; $i++) {
            $arr["key_$i"] = "value_$i";
        }
        $ch->send($arr);
    });

    $str = $ch->recv();
    echo "String length: " . strlen($str) . "\n";
    echo "String content OK: " . ($str === str_repeat("X", 1024 * 1024) ? "yes" : "no") . "\n";

    $arr = $ch->recv();
    echo "Array count: " . count($arr) . "\n";
    echo "Array sample: " . $arr["key_500"] . "\n";

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
String length: 1048576
String content OK: yes
Array count: 1000
Array sample: value_500
Done
