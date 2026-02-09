--TEST--
fgetcsv and fputcsv work correctly through async IO path
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    // Write CSV
    $fp = fopen($tmpfile, 'w');
    fputcsv($fp, ["name", "age", "city"], ",", "\"", "\\");
    fputcsv($fp, ["Alice", "30", "New York"], ",", "\"", "\\");
    fputcsv($fp, ["Bob", "25", "London"], ",", "\"", "\\");
    fclose($fp);

    // Read CSV
    $fp = fopen($tmpfile, 'r');
    $rows = [];
    while (($row = fgetcsv($fp, 0, ",", "\"", "\\")) !== false) {
        $rows[] = $row;
    }
    fclose($fp);

    echo "Rows: " . count($rows) . "\n";
    echo "Header: " . implode(', ', $rows[0]) . "\n";
    echo "Row 1: " . implode(', ', $rows[1]) . "\n";
    echo "Row 2: " . implode(', ', $rows[2]) . "\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Rows: 3
Header: name, age, city
Row 1: Alice, 30, New York
Row 2: Bob, 25, London
Result: done
End
