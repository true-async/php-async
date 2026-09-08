--TEST--
Casting a handle to a file descriptor while another coroutine is inside fread()
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open() is disabled');
if (PHP_OS_FAMILY === 'Windows') die('skip POSIX only');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "Start\n";

/* proc_open() casts the handle with PHP_STREAM_AS_FD, which flushes it, seeks
 * the descriptor and drops the read buffer under the parked reader. */
$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
$body = '';
for ($i = 0; $i < 20000; $i++) {
    $body .= sprintf("%015d\n", $i);
}
file_put_contents($tmpfile, $body);

$handle = fopen($tmpfile, 'r');

$reader = spawn(function () use ($handle) {
    $data = '';
    while (true) {
        $chunk = @fread($handle, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    return $data;
});

$caster = spawn(function () use ($handle) {
    suspend();
    $descriptors = [0 => $handle, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    /* The child must not read the descriptor: it shares the file offset, and
     * bytes it consumed would be missing from the reader for good. */
    $process = @proc_open('exit 0', $descriptors, $pipes);
    if (is_resource($process)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
    return true;
});

[$results, $exceptions] = await_all([$reader, $caster]);

$data = (string) $results[0];
printf("bytes: %d of %d\n", strlen($data), strlen($body));
printf("in order: %s\n", var_export($data === substr($body, 0, strlen($data)), true));
echo "Exceptions: " . count($exceptions) . "\n";

fclose($handle);
unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
bytes: 320000 of 320000
in order: true
Exceptions: 0
End
