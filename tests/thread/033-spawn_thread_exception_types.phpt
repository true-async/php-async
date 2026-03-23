--TEST--
spawn_thread() - various exception types propagate correctly
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
    // TypeError
    try {
        await(spawn_thread(function() {
            /** @noinspection PhpStrictTypeCheckingInspection */
            strlen([]); // @phpstan-ignore-line
        }));
    } catch (\Async\RemoteException $e) {
        echo "TypeError: " . $e->getRemoteClass() . "\n";
    }

    // DivisionByZeroError
    try {
        await(spawn_thread(function() {
            return intdiv(1, 0);
        }));
    } catch (\Async\RemoteException $e) {
        echo "DivisionByZero: " . $e->getRemoteClass() . "\n";
    }

    // Custom message
    try {
        await(spawn_thread(function() {
            throw new \InvalidArgumentException("bad input", 42);
        }));
    } catch (\Async\RemoteException $e) {
        $remote = $e->getRemoteException();
        echo "message: " . $remote->getMessage() . "\n";
        echo "code: " . $remote->getCode() . "\n";
    }
});
?>
--EXPECT--
TypeError: TypeError
DivisionByZero: DivisionByZeroError
message: bad input
code: 42
