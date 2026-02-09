--TEST--
Reading from empty file returns EOF immediately
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    // Create empty file
    $fp = fopen($tmpfile, 'w');
    fclose($fp);

    // Open and try to read
    $fp = fopen($tmpfile, 'r');

    echo "EOF before read: " . (feof($fp) ? "yes" : "no") . "\n";

    $data = fread($fp, 1024);
    echo "Read length: " . strlen($data) . "\n";
    echo "EOF after read: " . (feof($fp) ? "yes" : "no") . "\n";

    // Second read should also be instant
    $data2 = fread($fp, 1024);
    echo "Second read length: " . strlen($data2) . "\n";

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
EOF before read: no
Read length: 0
EOF after read: yes
Second read length: 0
Result: done
End
