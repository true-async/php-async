--TEST--
Closing the source handle while another coroutine is inside stream_copy_to_stream()
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

const ROUNDS = 10;

$source = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($source, str_repeat('0123456789abcdef', 200000));
$target = tempnam(sys_get_temp_dir(), 'async_io_test_');

for ($round = 0; $round < ROUNDS; $round++) {
    $in = fopen($source, 'r');
    $out = fopen($target, 'w');

    /* A non-empty read buffer keeps the copy out of the descriptor-level fast
     * path, so it alternates parking reads and writes over the two handles. */
    fread($in, 10);

    $copier = spawn(fn() => @stream_copy_to_stream($in, $out));
    $closer = spawn(function () use ($in) {
        suspend();
        suspend();
        @fclose($in);
        return true;
    });

    await_all([$copier, $closer]);
    @fclose($out);
}

echo "Copy survived\n";

unlink($source);
unlink($target);
echo "End\n";

?>
--EXPECT--
Start
Copy survived
End
