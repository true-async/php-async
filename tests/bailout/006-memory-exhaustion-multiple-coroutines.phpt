--TEST--
Memory exhaustion bailout with multiple coroutines consuming memory
--INI--
memory_limit=2M
--FILE--
<?php

use function Async\spawn;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

spawn(function() {
    echo "Coroutine 1 started\n";
    $data1 = str_repeat('x', 500000);
    echo "Coroutine 1 allocated 500KB\n";
});

spawn(function() {
    echo "Coroutine 2 started\n";
    $data2 = str_repeat('x', 10000000);
    echo "Coroutine 2 should cause bailout\n";
});

spawn(function() {
    echo "Coroutine 3 started\n";
    echo "Coroutine 3 should not reach\n";
});

echo "After spawn\n";

?>
--EXPECTF--
Before spawn
After spawn
Coroutine 1 started
Coroutine 1 allocated 500KB
Coroutine 2 started

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called