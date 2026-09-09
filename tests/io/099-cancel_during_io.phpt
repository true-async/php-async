--TEST--
Cancelling a coroutine whose read or write is still in the thread pool
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

/* uv_cancel does not stop a worker that has started, so the operation outlives
 * the coroutine and the buffer it names must outlive it too. The defect is
 * silent without a sanitizer: this is a stress test for the ASAN job, and the
 * counts are what made it fire — a worker was measured inside the syscall in
 * 39 rounds out of 100. */
const ROUNDS = 100;
const SIZE = 4 * 1024 * 1024;

$source = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($source, str_repeat('0123456789abcdef', SIZE / 16));

$target = tempnam(sys_get_temp_dir(), 'async_io_test_');

for ($round = 0; $round < ROUNDS; $round++) {
    $handle = fopen($source, 'r');
    stream_set_chunk_size($handle, SIZE);

    $reader = spawn(function () use ($handle) {
        try {
            @fread($handle, SIZE);
        } catch (Throwable) {
        }
    });

    suspend();
    $reader->cancel();
    await_all([$reader]);
    fclose($handle);

    $handle = fopen($target, 'w');

    $writer = spawn(function () use ($handle) {
        try {
            @fwrite($handle, str_repeat('x', SIZE));
        } catch (Throwable) {
        }
    });

    suspend();
    $writer->cancel();
    await_all([$writer]);
    fclose($handle);
}

echo "Cancel survived\n";

/* The copy the fix adds is on every read, so one uncancelled round says the
 * bytes still arrive, and arrive in full. */
$read = await(spawn(function () use ($source) {
    $handle = fopen($source, 'r');
    stream_set_chunk_size($handle, 65536);
    $content = '';
    while (($chunk = fread($handle, 65536)) !== '' && $chunk !== false) {
        $content .= $chunk;
    }
    fclose($handle);
    return $content;
}));

printf("read %d bytes, %s\n", strlen($read), $read === file_get_contents($source) ? 'same' : 'DIFFERENT');

/* The write copy needs the same check: a wrong length or offset there would
 * leave the file short or shifted, and the cancelled rounds never look. A file
 * of its own, because a worker from the last cancelled round may still be
 * writing into $target. */
$checked = tempnam(sys_get_temp_dir(), 'async_io_test_');
$payload = str_repeat('0123456789abcdef', 65536);
await(spawn(function () use ($checked, $payload) {
    $handle = fopen($checked, 'w');
    fwrite($handle, $payload);
    fclose($handle);
}));

printf("wrote %d bytes, %s\n", filesize($checked), file_get_contents($checked) === $payload ? 'same' : 'DIFFERENT');

unlink($source);
unlink($target);
unlink($checked);
echo "End\n";

?>
--EXPECT--
Start
Cancel survived
read 4194304 bytes, same
wrote 1048576 bytes, same
End
