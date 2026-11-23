--TEST--
exit() in a coroutine starts the graceful shutdown: the script ends and other coroutines are cancelled
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

register_shutdown_function(function () {
    echo "shutdown function\n";
});

spawn(function () {
    try {
        delay(200);
        echo "other coroutine finished\n";
    } finally {
        echo "other coroutine unwound\n";
    }
});

spawn(function () {
    delay(10);
    echo "exit\n";
    exit(3);
});

delay(100);
echo "main still ran\n";
?>
--EXPECT--
exit
other coroutine unwound
shutdown function
