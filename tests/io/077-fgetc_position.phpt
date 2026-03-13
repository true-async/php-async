--TEST--
fgetc() advances position by 1 on each call in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'ABCDE');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');

    $chars = [];
    while (($c = fgetc($fp)) !== false) {
        $chars[] = $c;
        echo "fgetc: '$c' ftell: " . ftell($fp) . "\n";
    }

    echo "feof: " . (feof($fp) ? 'true' : 'false') . "\n";
    echo "chars: " . implode('', $chars) . "\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
fgetc: 'A' ftell: 1
fgetc: 'B' ftell: 2
fgetc: 'C' ftell: 3
fgetc: 'D' ftell: 4
fgetc: 'E' ftell: 5
feof: true
chars: ABCDE
End
