--TEST--
exit() in the main script starts the graceful shutdown: other coroutines are cancelled
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

spawn(function () {
    try {
        delay(200);
        echo "other coroutine finished\n";
    } finally {
        echo "other coroutine unwound\n";
    }
});

delay(10);
echo "exit\n";
exit(3);
?>
--EXPECT--
exit
other coroutine unwound
