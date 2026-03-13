--TEST--
After close+reopen in 'a' mode, ftell is 0 and new writes go to current EOF
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'INIT');

$coroutine = spawn(function() use ($tmpfile) {
    // First open
    $fp = fopen($tmpfile, 'a');
    echo "ftell on first open: " . ftell($fp) . "\n";
    fwrite($fp, '_A');
    echo "ftell after first write: " . ftell($fp) . "\n";
    fclose($fp);

    // Reopen — file is now 'INIT_A' (6 bytes); ftell must be 0
    $fp = fopen($tmpfile, 'a');
    echo "ftell on second open: " . ftell($fp) . "\n";
    fwrite($fp, '_B');
    echo "ftell after second write: " . ftell($fp) . "\n";
    fclose($fp);

    echo "file: '" . file_get_contents($tmpfile) . "'\n";
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on first open: 0
ftell after first write: 2
ftell on second open: 0
ftell after second write: 2
file: 'INIT_A_B'
End
