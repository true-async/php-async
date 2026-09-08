--TEST--
Appending a filter that waits for the buffer lock while the handle is closed
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

const ROUNDS = 10;

$source = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($source, str_repeat('0123456789abcdef', 200000));

/* The append queues behind the reader's lock and wakes into a closed stream,
 * so it never reaches the filter chain it would otherwise be unlinked from. */
for ($round = 0; $round < ROUNDS; $round++) {
    $handle = fopen($source, 'r');

    $reader = spawn(fn() => strlen((string) @fread($handle, 3200000)));
    $appender = spawn(function () use ($handle) {
        suspend();
        return @stream_filter_append($handle, 'string.toupper', STREAM_FILTER_READ);
    });
    $closer = spawn(function () use ($handle) {
        suspend();
        suspend();
        @fclose($handle);
        return true;
    });

    await_all([$reader, $appender, $closer]);
}

echo "Append survived\n";

unlink($source);
echo "End\n";

?>
--EXPECT--
Start
Append survived
End
