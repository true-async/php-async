--TEST--
Changing the chunk size while another coroutine is inside a filtered read
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

/* The filter holds what it is fed and releases one byte per call, so the fill
 * loop keeps reading; suspending hands the resizer its turn in the middle of
 * that loop, and the counter proves the loop went on afterwards. */
class Drip extends php_user_filter
{
    public static int $callsAfterResize = 0;

    private string $held = '';

    public function filter($in, $out, &$consumed, $closing): int
    {
        /* Every call, so the reader yields between two reads whatever the file
         * backend does with a page-cache hit. */
        suspend();

        if ($GLOBALS['resized']) {
            self::$callsAfterResize++;
        }

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $this->held .= $bucket->data;
        }

        if (strlen($this->held) > 1) {
            stream_bucket_append($out, stream_bucket_new($this->stream, $this->held[0]));
            $this->held = substr($this->held, 1);
            return PSFS_PASS_ON;
        }

        return PSFS_FEED_ME;
    }
}

stream_filter_register('drip', 'Drip');

$GLOBALS['resized'] = false;

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('x', 65536));

$handle = fopen($tmpfile, 'r');
stream_filter_append($handle, 'drip', STREAM_FILTER_READ);

$reader = spawn(fn() => strlen((string) fread($handle, 4096)));
$resizer = spawn(function () use ($handle) {
    suspend();
    $ok = stream_set_chunk_size($handle, 4000000);
    $GLOBALS['resized'] = true;
    return $ok;
});

[$results, $exceptions] = await_all([$reader, $resizer]);

printf("read: %s\n", var_export($results[0] > 0, true));
printf("read after the resize: %s\n", var_export(Drip::$callsAfterResize > 0, true));
echo "Exceptions: " . count($exceptions) . "\n";

fclose($handle);
unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
read: true
read after the resize: true
Exceptions: 0
End
