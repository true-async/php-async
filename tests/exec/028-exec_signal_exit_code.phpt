--TEST--
exec(), system() and passthru() return the signal number for a command killed by a signal
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR === '\\') die('skip Unix-only test');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Without TrueAsync the result is pclose()'s raw wait status; for a child killed
// by SIGKILL without a core dump that is 9.
exec('kill -9 $$', $output, $rc);
echo "exec top level: $rc\n";

echo "exec in coroutine: ", await(spawn(function () {
    exec('kill -9 $$', $output, $rc);
    return $rc;
})), "\n";

system('kill -9 $$', $rc);
echo "system: $rc\n";

passthru('kill -9 $$', $rc);
echo "passthru: $rc\n";

exec('exit 7', $output, $rc);
echo "normal exit: $rc\n";
?>
--EXPECT--
exec top level: 9
exec in coroutine: 9
system: 9
passthru: 9
normal exit: 7
