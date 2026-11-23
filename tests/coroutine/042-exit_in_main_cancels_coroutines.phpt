--TEST--
exit() in the main script starts the graceful shutdown: other coroutines are cancelled
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

$started = false;

spawn(function () use (&$started) {
    $started = true;

    try {
        delay(200);
        echo "other coroutine finished\n";
    } finally {
        echo "other coroutine unwound\n";
    }
});

// A coroutine cancelled before it starts runs no finally block.
while (!$started) {
    delay(1);
}

echo "exit\n";
exit(3);
?>
--EXPECT--
exit
other coroutine unwound
