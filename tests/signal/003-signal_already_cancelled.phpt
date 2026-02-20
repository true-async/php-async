--TEST--
Async\signal() - already completed cancellation returns rejected Future
--FILE--
<?php

use Async\Signal;
use function Async\signal;
use function Async\timeout;
use function Async\await;
use function Async\delay;

echo "start\n";

$t = timeout(1);
delay(50);

$future = signal(Signal::SIGINT, $t);

try {
    await($future);
    echo "Unexpected: should have been cancelled\n";
} catch (\Throwable $e) {
    echo "Cancelled: " . get_class($e) . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Cancelled: Async\TimeoutException
end
