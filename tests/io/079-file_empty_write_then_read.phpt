--TEST--
Writing to a newly created file then reading back works correctly in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');

    // File is empty on open
    echo "ftell on open: " . ftell($fp) . "\n";
    echo "feof on empty file: " . (feof($fp) ? 'true' : 'false') . "\n";

    // Write binary data
    $data = "\x00\x01\x02\xFF\xFE\xFD";
    fwrite($fp, $data);
    echo "ftell after binary write: " . ftell($fp) . "\n";

    // Seek to start and read back
    fseek($fp, 0, SEEK_SET);
    $got = fread($fp, 100);
    echo "length match: " . (strlen($got) === 6 ? 'yes' : 'no') . "\n";
    echo "content match: " . ($got === $data ? 'yes' : 'no') . "\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on open: 0
feof on empty file: false
ftell after binary write: 6
length match: yes
content match: yes
End
