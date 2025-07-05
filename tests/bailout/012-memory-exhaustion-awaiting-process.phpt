--TEST--
Memory exhaustion bailout while awaiting coroutine processing
--INI--
memory_limit=2M
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

$coroutine = spawn(function() {
    echo "Coroutine started\n";
    suspend();
    echo "Coroutine resumed\n";
    return "result";
});

echo "Before await\n";

spawn(function() use ($coroutine) {
    echo "Memory exhaustion coroutine started\n";
    str_repeat('x', 10000000);
    echo "After memory exhaustion (should not reach)\n";
});

$result = await($coroutine);
echo "After await (should not reach)\n";

?>
--EXPECTF--
Before spawn
Before await
Coroutine started
Memory exhaustion coroutine started

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called