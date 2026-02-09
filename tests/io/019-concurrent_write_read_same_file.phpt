--TEST--
Concurrent write and read on same file from different coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

// First write the file
$writer = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w');
    for ($i = 0; $i < 100; $i++) {
        fwrite($fp, "line-$i\n");
    }
    fclose($fp);
    return "written";
});

await($writer);
echo "File written\n";

// Now read from two coroutines concurrently
$reader1 = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');
    $lines = 0;
    while (($line = fgets($fp)) !== false) {
        $lines++;
    }
    fclose($fp);
    return $lines;
});

$reader2 = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');
    $content = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 256);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $content .= $chunk;
    }
    fclose($fp);
    return substr_count($content, "\n");
});

[$results, $exceptions] = await_all([$reader1, $reader2]);

echo "Reader1 lines: " . $results[0] . "\n";
echo "Reader2 lines: " . $results[1] . "\n";
echo "Exceptions: " . count($exceptions) . "\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
File written
Reader1 lines: 100
Reader2 lines: 100
Exceptions: 0
End
