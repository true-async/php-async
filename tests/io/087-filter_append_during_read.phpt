--TEST--
Appending a read filter while another coroutine is inside fread()
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

/* The first read leaves 8182 bytes buffered, so the filter append has
 * pre-buffered data to wind through the chain, and the second read is parked
 * inside ops->read while it happens. */
$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
$body = '';
for ($i = 0; $i < 4000; $i++) {
    $body .= sprintf("%015d\n", $i);
}
file_put_contents($tmpfile, $body);

$handle = fopen($tmpfile, 'r');
fread($handle, 10);

$reader = spawn(fn() => @fread($handle, 20000));
$appender = spawn(function () use ($handle) {
    suspend();
    return @stream_filter_append($handle, 'convert.base64-encode', STREAM_FILTER_READ) !== false;
});

[$results, $exceptions] = await_all([$reader, $appender]);

$data = (string) $results[0];
printf("length: %d\n", strlen($data));
printf("raw: %s\n", var_export($data === substr($body, 10, strlen($data)), true));
echo "Exceptions: " . count($exceptions) . "\n";

fclose($handle);
unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
length: 20000
raw: true
Exceptions: 0
End
