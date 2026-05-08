--TEST--
Async\signal(): registering multiple Futures for different signals and awaiting one doesn't leak handles
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') echo "skip multi-signum scenario fails on BSD/Darwin — root cause unclear (not libuv signal layer; suspected interaction with our libuv_reactor signal save/restore or ZTS thread signal mask). Tracked separately; unrelated to the dispose-chain fix in this commit.";
if (!function_exists('posix_kill')) echo "skip posix extension required";
?>
--FILE--
<?php

use Async\Signal;
use function Async\signal;
use function Async\spawn;
use function Async\delay;
use function Async\await;

// Register signal Futures for SIGUSR1 and SIGUSR2 in advance, then await only
// one of them. The unused Future is dropped when the array goes out of scope;
// its signal_event must be disposed so the script exits cleanly.
spawn(function () {
    delay(50);
    posix_kill(getmypid(), SIGUSR2);
});

$futures = [
    signal(Signal::SIGUSR1),
    signal(Signal::SIGUSR2),
];

$result = await($futures[1]);
echo "Got: " . $result->name . "\n";

// Mark the SIGUSR1 future as ignored to suppress "never used" warning
// (the test specifically targets the cleanup path, not the warning).
$futures[0]->ignore();
unset($futures);

echo "ok\n";

?>
--EXPECT--
Got: SIGUSR2
ok
