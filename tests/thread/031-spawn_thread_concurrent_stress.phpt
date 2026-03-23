--TEST--
spawn_thread() - many concurrent threads
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $threads = [];
    for ($i = 0; $i < 8; $i++) {
        $threads[] = spawn_thread(function() use ($i) {
            return $i * $i;
        });
    }

    $results = [];
    foreach ($threads as $t) {
        $results[] = await($t);
    }

    echo implode(',', $results) . "\n";
});
?>
--EXPECT--
0,1,4,9,16,25,36,49
