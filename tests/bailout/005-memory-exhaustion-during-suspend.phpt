--TEST--
Memory exhaustion bailout during suspend operation
--INI--
memory_limit=2M
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

spawn(function() {
    echo "Before suspend\n";
    suspend();
    echo "After suspend\n";
    str_repeat('x', 10000000);
    echo "After memory exhaustion (should not reach)\n";
});

spawn(function() {
    echo "Other coroutine running\n";
});

echo "After spawn\n";

?>
--EXPECTF--
Before spawn
After spawn
Before suspend
Other coroutine running
After suspend

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called