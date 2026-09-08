--TEST--
Reading and writing one FIFO from two coroutines at the same time
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') die('skip POSIX only');
if (!function_exists('posix_mkfifo')) die('skip ext/posix required');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

echo "Start\n";

/* A FIFO comes from the plain files wrapper but has no descriptor offset to
 * share, so the writer must not queue behind the reader it has to release. */
$path = tempnam(sys_get_temp_dir(), 'async_io_test_');
unlink($path);
posix_mkfifo($path, 0600);

$handle = fopen($path, 'r+');

$reader = spawn(fn() => fread($handle, 5));
$writer = spawn(function () use ($handle) {
    delay(20);
    return fwrite($handle, "hello");
});

$read = await($reader);
$written = await($writer);

printf("written: %d\n", $written);
printf("read: %s\n", $read);

fclose($handle);
unlink($path);
echo "End\n";

?>
--EXPECT--
Start
written: 5
read: hello
End
