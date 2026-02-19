--TEST--
Async\signal() - timeout cancellation when no signal arrives
--FILE--
<?php

use Async\Signal;
use function Async\signal;
use function Async\timeout;
use function Async\await;

echo "start\n";

$future = signal(Signal::SIGINT, timeout(50));

try {
    await($future);
    echo "Unexpected: signal received\n";
} catch (Async\TimeoutException $e) {
    echo "Timeout: signal not received within 50ms\n";
}

echo "end\n";

?>
--EXPECT--
start
Timeout: signal not received within 50ms
end
