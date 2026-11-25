--TEST--
await() on a cancelled Timeout throws AsyncCancellation instead of waiting for a timer that never fires
--FILE--
<?php

use function Async\await;
use function Async\spawn;
use function Async\timeout;

await(spawn(function () {
    $timeout = timeout(50);
    $timeout->cancel();

    try {
        await($timeout);
    } catch (Async\AsyncCancellation $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
}));

echo "end\n";

?>
--EXPECT--
Async\AsyncCancellation: Timeout has been cancelled
end
