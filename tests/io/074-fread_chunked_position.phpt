--TEST--
fread() in a loop advances position correctly; ftell matches bytes consumed
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('Z', 50));

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');
    $total = 0;
    $reads = 0;

    while (!feof($fp)) {
        $chunk = fread($fp, 10);
        if ($chunk === '') break;
        $total += strlen($chunk);
        $reads++;
    }

    echo "total bytes read: $total\n";
    echo "read calls: $reads\n";
    echo "ftell at end: " . ftell($fp) . "\n";
    echo "feof: " . (feof($fp) ? 'true' : 'false') . "\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
total bytes read: 50
read calls: 5
ftell at end: 50
feof: true
End
