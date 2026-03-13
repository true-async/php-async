--TEST--
fseek beyond EOF then fread returns empty string (not an error)
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'HELLO');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r+');

    // Seek past EOF
    fseek($fp, 100, SEEK_SET);
    echo "ftell after fseek(100): " . ftell($fp) . "\n";
    echo "feof before read: " . (feof($fp) ? 'true' : 'false') . "\n";

    // Read at position past EOF returns ''
    $data = fread($fp, 10);
    echo "read past EOF: '" . $data . "'\n";
    echo "feof after read: " . (feof($fp) ? 'true' : 'false') . "\n";

    // Seek back to 0 and verify normal read
    fseek($fp, 0, SEEK_SET);
    echo "feof after fseek(0): " . (feof($fp) ? 'true' : 'false') . "\n";
    echo "read from 0: '" . fread($fp, 5) . "'\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell after fseek(100): 100
feof before read: false
read past EOF: ''
feof after read: true
feof after fseek(0): false
read from 0: 'HELLO'
End
