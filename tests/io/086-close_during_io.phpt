--TEST--
Closing a handle from another coroutine while it is being read and written
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

const ROUNDS = 20;

$source = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($source, str_repeat("0123456789abcdef", 200000));

$target = tempnam(sys_get_temp_dir(), 'async_io_test_');

/* The closing coroutine runs while the other one is parked inside the read or
 * the write, which is where the stream is freed. */
$closeAfterTwoSwitches = function ($handle) {
    suspend();
    suspend();
    @fclose($handle);
    return true;
};

for ($round = 0; $round < ROUNDS; $round++) {
    $handle = fopen($source, 'r');
    $reader = spawn(function () use ($handle) {
        $total = 0;
        while (true) {
            $chunk = @fread($handle, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $total += strlen($chunk);
        }
        return $total;
    });
    await_all([$reader, spawn(fn() => $closeAfterTwoSwitches($handle))]);
}

echo "Read survived\n";

for ($round = 0; $round < ROUNDS; $round++) {
    $handle = fopen($target, 'w');
    $writer = spawn(function () use ($handle) {
        $chunk = str_repeat('x', 200000);
        for ($i = 0; $i < 20; $i++) {
            if (@fwrite($handle, $chunk) === false) {
                break;
            }
        }
        return true;
    });
    await_all([$writer, spawn(fn() => $closeAfterTwoSwitches($handle))]);
}

echo "Write survived\n";

unlink($source);
unlink($target);
echo "End\n";

?>
--EXPECT--
Start
Read survived
Write survived
End
