--TEST--
fwrite with zero-length string does not corrupt file position
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');
    fwrite($fp, "Hello");
    echo "ftell after write: " . ftell($fp) . "\n";

    // Write empty string
    $result = fwrite($fp, "");
    echo "fwrite empty returned: " . var_export($result, true) . "\n";
    echo "ftell after empty write: " . ftell($fp) . "\n";

    // Write more data
    fwrite($fp, "World");
    echo "ftell after second write: " . ftell($fp) . "\n";

    // Verify
    fseek($fp, 0, SEEK_SET);
    $data = fread($fp, 1024);
    echo "Content: '$data'\n";

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
ftell after write: 5
fwrite empty returned: 0
ftell after empty write: 5
ftell after second write: 10
Content: 'HelloWorld'
Result: done
End
