--TEST--
Write to file and read back in separate coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$writer = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w');
    fwrite($fp, "line1\n");
    fwrite($fp, "line2\n");
    fwrite($fp, "line3\n");
    fclose($fp);
    return "written";
});

$status = await($writer);
echo "Writer: $status\n";

$reader = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');
    $lines = [];
    while (($line = fgets($fp)) !== false) {
        $lines[] = trim($line);
    }
    fclose($fp);
    return $lines;
});

$lines = await($reader);
echo "Lines read: " . count($lines) . "\n";
foreach ($lines as $line) {
    echo "  $line\n";
}

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Writer: written
Lines read: 3
  line1
  line2
  line3
End
