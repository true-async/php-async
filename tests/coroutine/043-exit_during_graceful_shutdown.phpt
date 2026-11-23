--TEST--
exit() in a coroutine unwinding from the graceful shutdown ends only that coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

spawn(function () {
    try {
        delay(200);
    } finally {
        echo "first unwound, exits\n";
        exit(4);
    }
});

spawn(function () {
    try {
        delay(200);
    } finally {
        echo "second unwound\n";
    }
});

delay(10);
throw new RuntimeException("boom");
?>
--EXPECTF--
%AFatal error: Uncaught RuntimeException: boom%A
