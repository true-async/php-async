--TEST--
Async file read and write in coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w');
    fwrite($fp, "Hello from async coroutine!");
    fclose($fp);
    echo "Write complete\n";

    $fp = fopen($tmpfile, 'r');
    $data = fread($fp, 1024);
    fclose($fp);
    echo "Read: '$data'\n";

    return strlen($data);
});

$len = await($coroutine);
echo "Length: $len\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Write complete
Read: 'Hello from async coroutine!'
Length: 27
End
