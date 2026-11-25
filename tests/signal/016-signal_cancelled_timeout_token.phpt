--TEST--
signal() with a cancelled Timeout as its token waits for the signal as without a token
--EXTENSIONS--
posix
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
?>
--FILE--
<?php

use Async\Signal;
use function Async\await;
use function Async\delay;
use function Async\signal;
use function Async\spawn;
use function Async\timeout;

await(spawn(function () {
    $timeout = timeout(50);
    $timeout->cancel();

    $future = signal(Signal::SIGUSR1, $timeout);
    spawn(function () {
        delay(10);
        posix_kill(getmypid(), SIGUSR1);
    });

    var_dump(await($future) === Signal::SIGUSR1);
}));

echo "end\n";

?>
--EXPECT--
bool(true)
end
