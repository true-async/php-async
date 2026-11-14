--TEST--
fclose() is refused while a write filter is parked, after a read filter has left
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

/* The read and the write side lock independently, so a read filter and a write
 * filter of one stream run at once. The read filter leaving must not lift the
 * close guard the parked write filter still needs. */
final class Sync
{
    public static bool $readerIn = false;
    public static bool $writerIn = false;
    public static bool $readerOut = false;
    public static bool $closeTried = false;
}

class ReadGate extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        Sync::$readerIn = true;

        gate('writerIn', fn() => Sync::$writerIn);

        return PSFS_PASS_ON;
    }
}

class WriteGate extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        if (!$closing) {
            Sync::$writerIn = true;

            gate('closeTried', fn() => Sync::$closeTried);
        }

        return PSFS_PASS_ON;
    }
}

function gate(string $name, Closure $open): void
{
    $deadline = hrtime(true) + 5_000_000_000;

    while (!$open()) {
        if (hrtime(true) > $deadline) {
            echo "gate '$name' never opened\n";
            return;
        }
        suspend();
    }
}

stream_filter_register('read-gate', 'ReadGate');
stream_filter_register('write-gate', 'WriteGate');

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('x', 1000));

$handle = fopen($tmpfile, 'r+');
stream_filter_append($handle, 'read-gate', STREAM_FILTER_READ);
stream_filter_append($handle, 'write-gate', STREAM_FILTER_WRITE);

$reader = spawn(function () use ($handle) {
    $data = fread($handle, 10);
    Sync::$readerOut = true;
    return strlen((string) $data);
});

$writer = spawn(function () use ($handle) {
    gate('readerIn', fn() => Sync::$readerIn);
    return fwrite($handle, 'abcdefgh');
});

$closer = spawn(function () use ($handle) {
    gate('readerOut', fn() => Sync::$readerOut);
    $closed = fclose($handle);
    Sync::$closeTried = true;
    return $closed;
});

[$results, $exceptions] = await_all([$reader, $writer, $closer]);

echo "read: ", var_export($results[0], true), "\n";
echo "write: ", var_export($results[1], true), "\n";
echo "close while parked: ", var_export($results[2], true), "\n";
echo "Exceptions: ", count($exceptions), "\n";
echo "close after: ", var_export(fclose($handle), true), "\n";

unlink($tmpfile);

?>
--EXPECTF--
Warning: fclose(): cannot close the provided stream, as it must not be manually closed in %s on line %d
read: 10
write: 8
close while parked: false
Exceptions: 0
close after: true
