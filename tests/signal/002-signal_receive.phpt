--TEST--
Async\signal() - receive signal from another coroutine
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
if (!function_exists('posix_kill')) echo "skip posix extension required";
?>
--FILE--
<?php

use Async\Signal;
use function Async\signal;
use function Async\await;
use function Async\spawn;

echo "start\n";

$future = signal(Signal::SIGUSR1);

spawn(function() {
    posix_kill(getmypid(), Signal::SIGUSR1->value);
});

$result = await($future);
echo "Signal received: " . $result->name . "\n";
var_dump($result === Signal::SIGUSR1);

echo "end\n";

?>
--EXPECT--
start
Signal received: SIGUSR1
bool(true)
end
