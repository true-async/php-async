--TEST--
Async\signal(): Future that is created but never awaited or referenced does not keep the reactor alive
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
?>
--FILE--
<?php

use Async\Signal;
use function Async\signal;
use function Async\spawn;
use function Async\delay;
use function Async\await;

// Create a signal Future that we never await — its underlying signal_event must
// still be disposed when the Future PHP object is GC'd, otherwise the reactor
// would refuse to exit.
//
// We sandwich it between an awaited delay so the reactor actually runs and the
// signal_event would be observable if it leaked.
$dropped = signal(Signal::SIGUSR1);
$dropped->ignore();   // suppress "never used" warning — it's intentional here
unset($dropped);

spawn(function () { delay(20); });
delay(50);

echo "ok\n";

?>
--EXPECT--
ok
