--TEST--
Coroutine cancel with invalid exception types
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "start\n";

$invalid_cancel_coroutine = spawn(function() {
    echo "invalid cancel coroutine started\n";
    suspend();
    return "cancel_result";
});

suspend(); // Let it start

try {
    $invalid_cancel_coroutine->cancel("not an exception");
    echo "should not accept string for cancel\n";
} catch (\TypeError $e) {
    echo "cancel string TypeError: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "cancel string unexpected: " . get_class($e) . "\n";
}

try {
    $invalid_cancel_coroutine->cancel(new \RuntimeException("Wrong exception type"));
    echo "accepted RuntimeException for cancel\n";
} catch (\TypeError $e) {
    echo "cancel RuntimeException TypeError: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "cancel RuntimeException unexpected: " . get_class($e) . "\n";
}

// Valid cancellation
$invalid_cancel_coroutine->cancel(new \Async\CancellationException("Valid cancellation"));

echo "end\n";

?>
--EXPECTF--
start
invalid cancel coroutine started
cancel string TypeError: %a
cancel RuntimeException TypeError:%a
end