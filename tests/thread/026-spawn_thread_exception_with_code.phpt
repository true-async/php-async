--TEST--
spawn_thread() - exception with code and previous
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
        $prev = new \LogicException("root cause");
        throw new \RuntimeException("operation failed", 500, $prev);
    });

    try {
        await($thread);
    } catch (\Async\RemoteException $e) {
        echo "message: " . $e->getMessage() . "\n";
        echo "code: " . $e->getCode() . "\n";
        echo "remote class: " . $e->getRemoteClass() . "\n";

        $remote = $e->getRemoteException();
        echo "remote message: " . $remote->getMessage() . "\n";
        echo "remote code: " . $remote->getCode() . "\n";

        $prev = $remote->getPrevious();
        echo "previous: " . ($prev ? $prev->getMessage() : "none") . "\n";
    }
});
?>
--EXPECT--
message: operation failed
code: 500
remote class: RuntimeException
remote message: operation failed
remote code: 500
previous: root cause
