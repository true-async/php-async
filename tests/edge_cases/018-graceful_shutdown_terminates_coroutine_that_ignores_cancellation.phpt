--TEST--
An uncaught exception ends the script although a coroutine catches its cancellation
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

spawn(function () {
    try {
        while (true) {
            try {
                delay(50);
            } catch (\Throwable $e) {
            }
        }
    } finally {
        echo "finally ran\n";
    }
});

spawn(function () {
    delay(20);
    throw new \RuntimeException('boom');
});
?>
--EXPECTF--
%AFatal error: Uncaught RuntimeException: boom%A
