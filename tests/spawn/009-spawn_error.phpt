--TEST--
Future: spawn() - error handling in coroutine
--FILE--
<?php

use function Async\spawn;

echo "start\n";

spawn(function() {
    echo "coroutine start\n";
    trigger_error("Test error", E_USER_ERROR);
    echo "coroutine end (should not print)\n";
});

echo "end\n";
?>
--EXPECTF--
start
end
coroutine start

Fatal error: Test error in %s on line %d