--TEST--
Sequential appends to same file from separate open/close cycles in coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'INIT');

$coroutine = spawn(function() use ($tmpfile) {
    // First append cycle
    $fp = fopen($tmpfile, 'a');
    fwrite($fp, "_AAA");
    fclose($fp);

    // Second append cycle
    $fp = fopen($tmpfile, 'a');
    fwrite($fp, "_BBB");
    fclose($fp);

    // Third append cycle
    $fp = fopen($tmpfile, 'a');
    fwrite($fp, "_CCC");
    fclose($fp);

    return file_get_contents($tmpfile);
});

$content = await($coroutine);
echo "Content: '$content'\n";
echo "Length: " . strlen($content) . "\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Content: 'INIT_AAA_BBB_CCC'
Length: 16
End
