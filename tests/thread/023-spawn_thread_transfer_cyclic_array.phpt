--TEST--
spawn_thread() - reference in return value throws error
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
        $a = 1;
        $arr = [];
        $arr[0] = &$a;
        $arr[1] = &$a;
        return $arr;
    });

    try {
        await($thread);
        echo "ERROR: should not reach here\n";
    } catch (\Async\RemoteException $e) {
        echo "caught: references not transferable\n";
    } catch (\Async\ThreadTransferException $e) {
        echo "caught: references not transferable\n";
    }
});
?>
--EXPECTF--
%Acaught: references not transferable
