--TEST--
spawn_thread() - exception in thread propagates to parent
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
        throw new RuntimeException("thread error");
    });

    try {
        await($thread);
        echo "ERROR: should not reach here\n";
    } catch (\Async\RemoteException $e) {
        echo "caught: " . $e->getMessage() . "\n";
        echo "remote class: " . $e->getRemoteClass() . "\n";
        $remote = $e->getRemoteException();
        if ($remote !== null) {
            echo "remote message: " . $remote->getMessage() . "\n";
        }
    }
});
?>
--EXPECT--
caught: thread error
remote class: RuntimeException
remote message: thread error
