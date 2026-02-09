--TEST--
fscanf works in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "Alice 30\nBob 25\nCharlie 35\n");

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');

    $people = [];
    while ($row = fscanf($fp, "%s %d\n")) {
        list($name, $age) = $row;
        $people[] = "$name=$age";
    }
    fclose($fp);

    echo "Count: " . count($people) . "\n";
    echo "People: " . implode(', ', $people) . "\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Count: 3
People: Alice=30, Bob=25, Charlie=35
Result: done
End
