--TEST--
Future: spawn() - exception handling in coroutine with await
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

spawn(function() {
    echo "coroutine start\n";
    spawn(fn() => throw new \Exception('error'));
});

echo "end\n";
?>
--EXPECTF--
start
end
coroutine start

Fatal error: Uncaught Exception: error in %s012-spawn_error-from-await.php:%d
Stack trace:
#0 [internal function]: {closure:%s:%d}()
#1 {main}
  thrown in %s012-spawn_error-from-await.php on line %d