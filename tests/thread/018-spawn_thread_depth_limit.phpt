--TEST--
spawn_thread() - deeply nested array exceeds transfer depth limit
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
        $nested = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $nested = [$nested];
        }
        return $nested;
    });

    try {
        await($thread);
        echo "ERROR: should not reach here\n";
    } catch (\Async\RemoteException $e) {
        echo "caught depth limit\n";
    } catch (\Async\ThreadTransferException $e) {
        echo "caught depth limit\n";
    }
});
?>
--EXPECTF--
%Acaught depth limit
