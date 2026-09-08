--TEST--
Closing a handle while another coroutine is inside stream_get_contents()
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

const ROUNDS = 10;

$source = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($source, str_repeat('0123456789abcdef', 200000));

/* php_stream_read() reports the bytes it delivered before parking, so the
 * caller loops once more over a handle another coroutine has already freed. */
$closeWhileParked = function ($handle) {
    suspend();
    suspend();
    @fclose($handle);
    return true;
};

for ($round = 0; $round < ROUNDS; $round++) {
    $handle = fopen($source, 'r');
    fread($handle, 10);
    $reader = spawn(fn() => strlen((string) @stream_get_contents($handle)));
    await_all([$reader, spawn(fn() => $closeWhileParked($handle))]);
}

echo "Unbounded read survived\n";

for ($round = 0; $round < ROUNDS; $round++) {
    $handle = fopen($source, 'r');
    fread($handle, 10);
    $reader = spawn(fn() => strlen((string) @stream_get_contents($handle, 20000)));
    await_all([$reader, spawn(fn() => $closeWhileParked($handle))]);
}

echo "Bounded read survived\n";

unlink($source);
echo "End\n";

?>
--EXPECT--
Start
Unbounded read survived
Bounded read survived
End
