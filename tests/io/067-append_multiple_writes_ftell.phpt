--TEST--
Multiple consecutive writes in 'a' mode: data appended correctly
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'INIT');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a');

    echo "ftell on open: " . ftell($fp) . "\n";

    fwrite($fp, '123');
    echo "ftell after write('123'): " . ftell($fp) . "\n";

    fwrite($fp, 'ABCDE');
    echo "ftell after write('ABCDE'): " . ftell($fp) . "\n";

    fwrite($fp, 'XY');
    echo "ftell after write('XY'): " . ftell($fp) . "\n";

    fclose($fp);

    echo "file: '" . file_get_contents($tmpfile) . "'\n";
    echo "length: " . strlen(file_get_contents($tmpfile)) . "\n";
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on open: 0
ftell after write('123'): 3
ftell after write('ABCDE'): 8
ftell after write('XY'): 10
file: 'INIT123ABCDEXY'
length: 14
End
