--TEST--
spawn_thread() - stdClass with cyclic refs rejected (dynamic properties)
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
        $a = new stdClass();
        $a->name = 'A';
        return $a;
    });

    try {
        await($thread);
        echo "ERROR: should not reach here\n";
    } catch (\Async\RemoteException $e) {
        echo "caught: dynamic properties\n";
    } catch (\Async\ThreadTransferException $e) {
        echo "caught: dynamic properties\n";
    }
});
?>
--EXPECTF--
%Acaught: dynamic properties
