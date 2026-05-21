--TEST--
Async\signal() - receive signal from another coroutine
--EXTENSIONS--
pcntl
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

// Use the PHP-level SIGUSR1 constant (native value, platform-correct) rather
// than Signal::SIGUSR1->value, which is hardcoded to the Linux number.
spawn(function() {
    posix_kill(getmypid(), SIGUSR1);
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
