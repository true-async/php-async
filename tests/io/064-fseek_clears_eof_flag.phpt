--TEST--
fseek() clears the EOF flag so subsequent reads succeed
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'ABCDE');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');

    // Read to EOF
    $data = fread($fp, 100);
    echo "first read: '$data'\n";
    echo "feof after full read: " . (feof($fp) ? 'true' : 'false') . "\n";

    // fseek must clear EOF
    fseek($fp, 0, SEEK_SET);
    echo "feof after fseek(0): " . (feof($fp) ? 'true' : 'false') . "\n";

    // Read again from position 0
    $data = fread($fp, 3);
    echo "read after fseek: '$data'\n";
    echo "ftell: " . ftell($fp) . "\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
first read: 'ABCDE'
feof after full read: true
feof after fseek(0): false
read after fseek: 'ABC'
ftell: 3
End
