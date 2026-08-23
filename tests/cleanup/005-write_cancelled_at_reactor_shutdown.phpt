--TEST--
A write cancelled during reactor shutdown builds no PHP object
--SKIPIF--
<?php
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
if (!preg_match('/Chaos hooks\s*=>\s*Enabled/', $info)) {
    die("skip requires a build configured with --enable-async-fuzz");
}
?>
--ENV--
ASYNC_FUZZ_CANCELLED_WRITE=1
--FILE--
<?php

use function Async\spawn;

// The coroutine only has to start the reactor; ASYNC_FUZZ_CANCELLED_WRITE does
// the rest at shutdown. A crash after the output below is the failure.
spawn(function () {
    echo "Coroutine done\n";
});

echo "Main done\n";
?>
--EXPECT--
Main done
Coroutine done
