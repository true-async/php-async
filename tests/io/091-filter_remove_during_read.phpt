--TEST--
Removing a read filter while another coroutine is inside fread()
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

/* The filter holds everything it is fed and releases it in one bucket when the
 * chain is flushed, so the removal lands a large block in the read buffer while
 * the other coroutine is parked in ops->read. */
class Hoarder extends php_user_filter
{
    private string $held = '';

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $this->held .= $bucket->data;
        }

        if ($closing) {
            if ($this->held === '') {
                return PSFS_FEED_ME;
            }
            stream_bucket_append($out, stream_bucket_new($this->stream, $this->held));
            $this->held = '';
            return PSFS_PASS_ON;
        }

        if (strlen($this->held) > 1) {
            stream_bucket_append($out, stream_bucket_new($this->stream, $this->held[0]));
            $this->held = substr($this->held, 1);
            return PSFS_PASS_ON;
        }

        return PSFS_FEED_ME;
    }
}

stream_filter_register('hoarder', 'Hoarder');

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('0123456789abcdef', 200000));

$handle = fopen($tmpfile, 'r');
$filter = stream_filter_append($handle, 'hoarder', STREAM_FILTER_READ);
for ($i = 0; $i < 12; $i++) {
    fread($handle, 1);
}

$reader = spawn(fn() => strlen((string) @fread($handle, 4096)));
$remover = spawn(function () use ($filter) {
    suspend();
    return @stream_filter_remove($filter);
});

[$results, $exceptions] = await_all([$reader, $remover]);

printf("read: %s\n", var_export($results[0] > 0, true));
echo "Exceptions: " . count($exceptions) . "\n";

fclose($handle);
unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
read: true
Exceptions: 0
End
