--TEST--
Closing a handle while a write filter is being flushed out of another coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

const ROUNDS = 10;

/* Removing the filter flushes the whole hoarded block through ops->write, which
 * parks; the close then frees the stream the flush writes its position back to. */
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

        return PSFS_FEED_ME;
    }
}

stream_filter_register('hoarder', 'Hoarder');

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

for ($round = 0; $round < ROUNDS; $round++) {
    $handle = fopen($tmpfile, 'w');
    $filter = stream_filter_append($handle, 'hoarder', STREAM_FILTER_WRITE);
    fwrite($handle, str_repeat('abcdefgh', 400000));

    $remover = spawn(fn() => @stream_filter_remove($filter));
    $closer = spawn(function () use ($handle) {
        suspend();
        @fclose($handle);
        return true;
    });

    await_all([$remover, $closer]);
}

echo "Filter removal survived\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Filter removal survived
End
