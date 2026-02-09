--TEST--
fread with exact file size followed by EOF detection
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "exactly16bytes!!");

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');

    // Read exactly the file size
    $data = fread($fp, 16);
    echo "Read: '$data'\n";
    echo "Length: " . strlen($data) . "\n";

    // EOF may or may not be set yet — depends on implementation
    // But next read must return 0 and set EOF
    $data2 = fread($fp, 1);
    echo "Next read length: " . strlen($data2) . "\n";
    echo "EOF: " . (feof($fp) ? "yes" : "no") . "\n";

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
Read: 'exactly16bytes!!'
Length: 16
Next read length: 0
EOF: yes
Result: done
End
