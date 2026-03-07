--TEST--
ftruncate to extend file beyond current size fills with null bytes
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');
    fwrite($fp, "ABC");

    echo "Size before: " . fstat($fp)['size'] . "\n";

    // Extend to 8 bytes
    ftruncate($fp, 8);
    echo "Size after extend: " . fstat($fp)['size'] . "\n";

    // Read entire file
    fseek($fp, 0, SEEK_SET);
    $data = fread($fp, 1024);
    echo "Length: " . strlen($data) . "\n";
    echo "First 3 bytes: '" . substr($data, 0, 3) . "'\n";
    echo "Padding is null bytes: " . (substr($data, 3) === "\0\0\0\0\0" ? "yes" : "no") . "\n";

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
Size before: 3
Size after extend: 8
Length: 8
First 3 bytes: 'ABC'
Padding is null bytes: yes
Result: done
End
