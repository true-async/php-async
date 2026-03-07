--TEST--
fseek beyond EOF then write creates sparse region
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');
    fwrite($fp, "ABC");

    // Seek past end of file
    fseek($fp, 10, SEEK_SET);
    echo "ftell after seek past EOF: " . ftell($fp) . "\n";

    // Write at position 10
    fwrite($fp, "XYZ");
    echo "ftell after write: " . ftell($fp) . "\n";

    // Read entire file
    fseek($fp, 0, SEEK_SET);
    $data = fread($fp, 1024);
    echo "Total length: " . strlen($data) . "\n";
    echo "First 3: '" . substr($data, 0, 3) . "'\n";
    echo "Last 3: '" . substr($data, -3) . "'\n";
    // Gap bytes 3..9 should be null
    echo "Gap is null: " . (substr($data, 3, 7) === str_repeat("\0", 7) ? "yes" : "no") . "\n";

    fclose($fp);
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell after seek past EOF: 10
ftell after write: 13
Total length: 13
First 3: 'ABC'
Last 3: 'XYZ'
Gap is null: yes
Result: done
End
