--TEST--
File seek followed by async read uses correct offset
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');
    fwrite($fp, "ABCDEFGHIJ");

    // Seek to position 5, then read — should get "FGHIJ"
    fseek($fp, 5, SEEK_SET);
    $data = fread($fp, 5);
    echo "After seek(5): '$data'\n";

    // Seek back to beginning
    fseek($fp, 0, SEEK_SET);
    $data = fread($fp, 3);
    echo "After seek(0): '$data'\n";

    // Seek relative
    fseek($fp, 2, SEEK_CUR);
    $data = fread($fp, 3);
    echo "After seek(+2): '$data'\n";

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
After seek(5): 'FGHIJ'
After seek(0): 'ABC'
After seek(+2): 'FGH'
Result: done
End
