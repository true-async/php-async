--TEST--
Two coroutines removing the same filter from one handle
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

/* The filter suspends inside the flush that the removal starts with, so the
 * second remover has taken hold of the same filter by the time the first one
 * frees it, and finds it gone when it wakes with the lock. */
class Parker extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        suspend();

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

stream_filter_register('parker', 'Parker');

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('0123456789abcdef', 4096));

$handle = fopen($tmpfile, 'r');
$filter = stream_filter_append($handle, 'parker', STREAM_FILTER_READ);
fread($handle, 10);

$first = spawn(fn() => @stream_filter_remove($filter));
$second = spawn(fn() => @stream_filter_remove($filter));

[$results, $exceptions] = await_all([$first, $second]);

printf("removed once: %s\n", var_export(count(array_filter($results)) === 1, true));
echo "Exceptions: " . count($exceptions) . "\n";

fclose($handle);
unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
removed once: true
Exceptions: 0
End
