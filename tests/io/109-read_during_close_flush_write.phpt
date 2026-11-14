--TEST--
A read cannot enter the stream while its close is parked writing out a deflate flush
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

/* No user code on the close path: the deflate flush of fclose() parks in the
 * write to the file, and a reader arriving meanwhile must find the stream
 * closing instead of a free read side. */
final class Sync
{
    public static bool $closing = false;
    public static bool $readerIn = false;
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

stream_filter_register('read-probe', 'ReadProbe');

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('x', 100));

$handle = fopen($tmpfile, 'r+');
stream_filter_append($handle, 'read-probe', STREAM_FILTER_READ);
stream_filter_append($handle, 'zlib.deflate', STREAM_FILTER_WRITE);
fwrite($handle, random_bytes(1 << 20));

$closer = spawn(function () use ($handle) {
    Sync::$closing = true;
    $closed = fclose($handle);
    Sync::$closed = true;
    return $closed;
});

$reader = spawn(function () use ($handle) {
    while (!Sync::$closing) {
        suspend();
    }
    return @fread($handle, 10);
});

[$results, $exceptions] = await_all([$closer, $reader]);

echo "close: ", var_export($results[0], true), "\n";
echo "reader entered the filter: ", var_export(Sync::$readerIn, true), "\n";
echo "Exceptions: ", count($exceptions), "\n";

unlink($tmpfile);

?>
--EXTENSIONS--
zlib
--EXPECT--
close: true
reader entered the filter: false
Exceptions: 0
