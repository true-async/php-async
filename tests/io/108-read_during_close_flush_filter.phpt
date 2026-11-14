--TEST--
A read cannot enter the stream while its close is parked in a closing write filter
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

/* fclose() passes its guard, then the close flush parks inside the write
 * filter. Until the close completes and frees the chain, no coroutine may
 * take a side of the stream and walk into a filter. */
final class Sync
{
    public static bool $closerInFlush = false;
    public static bool $readerIn = false;
    public static bool $readerDone = false;
    public static bool $closed = false;
}

class ReadProbe extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        Sync::$readerIn = true;

        while (!Sync::$closed) {
            suspend();
        }

        return PSFS_PASS_ON;
    }
}

class ClosingParker extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        if ($closing) {
            Sync::$closerInFlush = true;
            $deadline = hrtime(true) + 5_000_000_000;

            while (!Sync::$readerIn && !Sync::$readerDone && hrtime(true) < $deadline) {
                suspend();
            }
        }

        return PSFS_PASS_ON;
    }
}

stream_filter_register('read-probe', 'ReadProbe');
stream_filter_register('closing-parker', 'ClosingParker');

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('x', 100));

$handle = fopen($tmpfile, 'r+');
stream_filter_append($handle, 'read-probe', STREAM_FILTER_READ);
stream_filter_append($handle, 'closing-parker', STREAM_FILTER_WRITE);

$closer = spawn(function () use ($handle) {
    $closed = fclose($handle);
    Sync::$closed = true;
    return $closed;
});

$reader = spawn(function () use ($handle) {
    while (!Sync::$closerInFlush) {
        suspend();
    }
    $data = @fread($handle, 10);
    Sync::$readerDone = true;
    return $data;
});

[$results, $exceptions] = await_all([$closer, $reader]);

echo "close: ", var_export($results[0], true), "\n";
echo "reader entered the filter: ", var_export(Sync::$readerIn, true), "\n";
echo "read got data: ", var_export(is_string($results[1]) && $results[1] !== '', true), "\n";
echo "Exceptions: ", count($exceptions), "\n";

unlink($tmpfile);

?>
--EXPECT--
close: true
reader entered the filter: false
read got data: false
Exceptions: 0
