--TEST--
Multiple sequential writes maintain correct file offset
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w');

    fwrite($fp, "AAA");
    fwrite($fp, "BBB");
    fwrite($fp, "CCC");
    fwrite($fp, "DDD");
    fwrite($fp, "EEE");

    echo "Tell after writes: " . ftell($fp) . "\n";
    fclose($fp);

    // Read back and verify
    $fp = fopen($tmpfile, 'r');
    $data = fread($fp, 1024);
    fclose($fp);

    echo "Data: '$data'\n";
    echo "Length: " . strlen($data) . "\n";
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Tell after writes: 15
Data: 'AAABBBCCCDDDEEE'
Length: 15
Result: done
End
