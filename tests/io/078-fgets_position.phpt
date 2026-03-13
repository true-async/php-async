--TEST--
fgets() advances position correctly including newline bytes in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "line1\nline2\nline3");

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');

    $line = fgets($fp);
    echo "line1: '" . rtrim($line, "\n") . "' ftell: " . ftell($fp) . "\n";

    $line = fgets($fp);
    echo "line2: '" . rtrim($line, "\n") . "' ftell: " . ftell($fp) . "\n";

    $line = fgets($fp);
    echo "line3: '" . rtrim($line, "\n") . "' ftell: " . ftell($fp) . "\n";

    echo "feof: " . (feof($fp) ? 'true' : 'false') . "\n";

    // fgets returns false at EOF
    $line = fgets($fp);
    echo "fgets at EOF: " . ($line === false ? 'false' : "'$line'") . "\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
line1: 'line1' ftell: 6
line2: 'line2' ftell: 12
line3: 'line3' ftell: 17
feof: true
fgets at EOF: false
End
