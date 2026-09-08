--TEST--
Two copies running in opposite directions over the same pair of handles
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

/* Each copy holds one side of both handles; taken in different orders they
 * would wait for each other for the rest of the request. */
$one = tempnam(sys_get_temp_dir(), 'async_io_test_');
$two = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($one, str_repeat('a', 200000));
file_put_contents($two, str_repeat('b', 200000));

$first = fopen($one, 'r+');
$second = fopen($two, 'r+');

/* A byte out of each read buffer keeps both copies off the descriptor-level
 * fast path and makes the write side reach for the read side. */
fread($first, 1);
fread($second, 1);

[$results, $exceptions] = await_all([
    spawn(fn() => @stream_copy_to_stream($first, $second)),
    spawn(fn() => @stream_copy_to_stream($second, $first)),
]);

printf("copies finished: %d\n", count($results));
echo "Exceptions: " . count($exceptions) . "\n";

fclose($first);
fclose($second);
unlink($one);
unlink($two);
echo "End\n";

?>
--EXPECT--
Start
copies finished: 2
Exceptions: 0
End
