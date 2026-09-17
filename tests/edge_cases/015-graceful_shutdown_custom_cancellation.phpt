--TEST--
Async\graceful_shutdown(): the cancellation passed in reaches the coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\graceful_shutdown;
use Async\AsyncCancellation;

class ServerShutdown extends AsyncCancellation {}

spawn(function () {
    try {
        while (true) {
            suspend();
        }
    } catch (AsyncCancellation $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
});

spawn(function () {
    suspend();
    graceful_shutdown(new ServerShutdown('Server shutdown'));
});

try {
    suspend();
    suspend();
} catch (AsyncCancellation $e) {
    echo "main: ", get_class($e), "\n";
}

echo "end\n";

?>
--EXPECT--
main: ServerShutdown
end
ServerShutdown: Server shutdown
