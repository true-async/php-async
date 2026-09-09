--TEST--
A cancelled read leaves the bytes it took to the next reader
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

/* Every record carries its own number, so a hole in the file shows up as a
 * jump rather than as bytes that merely look wrong. */
$source = tempnam(sys_get_temp_dir(), 'async_io_test_');
$content = '';
for ($i = 0; $i < 65536; $i++) {
    $content .= sprintf('%015d ', $i);
}
file_put_contents($source, $content);

/* A read submitted with offset -1 moves the descriptor offset, so a reader
 * that leaves without its bytes takes them from everyone else. */
$handle = fopen($source, 'r');
stream_set_chunk_size($handle, 65536);

$reader = spawn(function () use ($handle) {
    try {
        @fread($handle, 65536);
    } catch (Throwable) {
    }
});

suspend();
$reader->cancel();
await_all([$reader]);

$next = await(spawn(fn() => fread($handle, 16)));

printf("next=%s ftell=%d\n", rtrim($next), ftell($handle));

fclose($handle);
unlink($source);
echo "End\n";

?>
--EXPECT--
Start
next=000000000000000 ftell=16
End
