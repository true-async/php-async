--TEST--
file() reads file into array in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "alpha\nbeta\ngamma\ndelta\n");

$coroutine = spawn(function() use ($tmpfile) {
    // file() with default flags
    $lines = file($tmpfile);
    echo "Lines: " . count($lines) . "\n";
    echo "First: " . rtrim($lines[0]) . "\n";
    echo "Last: " . rtrim($lines[3]) . "\n";

    // file() with FILE_IGNORE_NEW_LINES
    $lines2 = file($tmpfile, FILE_IGNORE_NEW_LINES);
    echo "Without newlines: '" . $lines2[1] . "'\n";

    // file() with FILE_SKIP_EMPTY_LINES
    file_put_contents($tmpfile, "one\n\ntwo\n\nthree\n");
    $lines3 = file($tmpfile, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
    echo "Skip empty: " . count($lines3) . "\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Lines: 4
First: alpha
Last: delta
Without newlines: 'beta'
Skip empty: 3
Result: done
End
