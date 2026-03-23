--TEST--
spawn_thread() - exit() in child thread produces ThreadTransferException
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
    $thread = spawn_thread(function() {
        exit(1);
    });

    try {
        await($thread);
        echo "ERROR: should not reach here\n";
    } catch (\Async\ThreadTransferException $e) {
        echo "caught transfer exception\n";
        echo "message not empty: " . (strlen($e->getMessage()) > 0 ? "yes" : "no") . "\n";
    }
});
?>
--EXPECT--
caught transfer exception
message not empty: yes
