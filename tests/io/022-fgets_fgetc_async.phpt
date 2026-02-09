--TEST--
fgets and fgetc work correctly through async IO path
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "line1\nline2\nline3\n");

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');

    // fgets
    $l1 = fgets($fp);
    echo "fgets 1: " . rtrim($l1, "\n") . "\n";

    // fgetc
    $c1 = fgetc($fp);
    $c2 = fgetc($fp);
    $c3 = fgetc($fp);
    echo "fgetc: '$c1$c2$c3'\n";

    // fgets for rest of line2
    $rest = fgets($fp);
    echo "fgets rest: " . rtrim($rest, "\n") . "\n";

    // fgets for line3
    $l3 = fgets($fp);
    echo "fgets 3: " . rtrim($l3, "\n") . "\n";

    // Trigger EOF detection with one more read
    $extra = fgetc($fp);
    echo "Extra read: " . ($extra === false ? "false" : "'$extra'") . "\n";
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
fgets 1: line1
fgetc: 'lin'
fgets rest: e2
fgets 3: line3
Extra read: false
EOF: yes
Result: done
End
