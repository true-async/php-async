--TEST--
ftell returns correct position after async writes and reads
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');

    echo "Initial pos: " . ftell($fp) . "\n";

    fwrite($fp, "AAAA");
    echo "After write 4: " . ftell($fp) . "\n";

    fwrite($fp, "BBBBBB");
    echo "After write 6: " . ftell($fp) . "\n";

    fseek($fp, 0, SEEK_SET);
    echo "After seek 0: " . ftell($fp) . "\n";

    $data = fread($fp, 4);
    echo "After read 4: " . ftell($fp) . " data='$data'\n";

    $data = fread($fp, 3);
    echo "After read 3: " . ftell($fp) . " data='$data'\n";

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
Initial pos: 0
After write 4: 4
After write 6: 10
After seek 0: 0
After read 4: 4 data='AAAA'
After read 3: 7 data='BBB'
Result: done
End
