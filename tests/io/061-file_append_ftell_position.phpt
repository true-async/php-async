--TEST--
ftell() returns 0 after fopen('a') and writes always go to EOF
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, str_repeat('X', 100));

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a');

    // ftell is 0 on open in append mode; ftell results after writes
    // are undefined per documentation, but writes must target EOF.
    $pos_open = ftell($fp);
    echo "ftell on open: $pos_open\n";

    fwrite($fp, "AAA");
    $pos_after_write = ftell($fp);
    echo "ftell after write(3): $pos_after_write\n";

    fseek($fp, 0, SEEK_SET);
    echo "ftell after fseek(0): " . ftell($fp) . "\n";

    fwrite($fp, "BBB");
    $pos_after_second = ftell($fp);
    echo "ftell after second write(3): $pos_after_second\n";

    fclose($fp);

    $content = file_get_contents($tmpfile);
    echo "File length: " . strlen($content) . "\n";
    // Both writes must have gone to EOF, not to position 0.
    echo "Ends with AAABBB: " . (str_ends_with($content, 'AAABBB') ? 'yes' : 'no') . "\n";
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on open: 0
ftell after write(3): 3
ftell after fseek(0): 0
ftell after second write(3): 3
File length: 106
Ends with AAABBB: yes
End
