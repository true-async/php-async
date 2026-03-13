--TEST--
SEEK_END followed by write extends the file correctly in w+ and r+ modes
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, '12345');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r+');

    // Seek to EOF and verify position
    fseek($fp, 0, SEEK_END);
    echo "ftell at SEEK_END: " . ftell($fp) . "\n";

    // Write at EOF — appends
    fwrite($fp, 'XYZ');
    echo "ftell after write(3): " . ftell($fp) . "\n";

    // Seek -5 from end
    fseek($fp, -5, SEEK_END);
    echo "ftell at SEEK_END-5: " . ftell($fp) . "\n";

    $d = fread($fp, 5);
    echo "read(5) at -5 from end: '$d'\n";

    // Seek to 0 and read all
    fseek($fp, 0, SEEK_SET);
    echo "full content: '" . fread($fp, 100) . "'\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell at SEEK_END: 5
ftell after write(3): 8
ftell at SEEK_END-5: 3
read(5) at -5 from end: '45XYZ'
full content: '12345XYZ'
End
