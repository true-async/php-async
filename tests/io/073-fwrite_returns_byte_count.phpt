--TEST--
fwrite() returns the number of bytes actually written in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');

    $n = fwrite($fp, 'Hello');
    echo "fwrite('Hello'): $n\n";

    $n = fwrite($fp, str_repeat('X', 1024));
    echo "fwrite(1024 bytes): $n\n";

    $n = fwrite($fp, '');
    echo "fwrite(''): $n\n";

    $n = fwrite($fp, 'A', 1);
    echo "fwrite('A', 1): $n\n";

    $n = fwrite($fp, 'BCD', 2);
    echo "fwrite('BCD', 2): $n\n";

    fclose($fp);

    // Append mode
    $fp = fopen($tmpfile, 'a');
    $n = fwrite($fp, 'APPEND');
    echo "fwrite in 'a': $n\n";
    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
fwrite('Hello'): 5
fwrite(1024 bytes): 1024
fwrite(''): 0
fwrite('A', 1): 1
fwrite('BCD', 2): 2
fwrite in 'a': 6
End
