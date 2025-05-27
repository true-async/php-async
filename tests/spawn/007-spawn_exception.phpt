--TEST--
Future: spawn() - regular exception handling in coroutine
--FILE--
<?php

use function Async\spawn;

echo "start\n";

spawn(function() {
    echo "coroutine start\n";
    throw new Exception("Test exception");
    echo "coroutine end (should not print)\n";
});

echo "end\n";
?>
--EXPECTF--
start
end
coroutine start

Fatal error: Uncaught Exception: Test exception in %s:%d
Stack trace:
%a