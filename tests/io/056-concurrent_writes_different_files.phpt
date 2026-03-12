--TEST--
Concurrent writes to different files from multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all;

echo "Start\n";

$tmpdir = sys_get_temp_dir();
$files = [];
for ($i = 0; $i < 3; $i++) {
    $files[$i] = tempnam($tmpdir, 'async_io_test_');
}

$coroutines = [];
foreach ($files as $i => $file) {
    $coroutines[] = spawn(function() use ($file, $i) {
        $fp = fopen($file, 'w');
        for ($j = 0; $j < 100; $j++) {
            fwrite($fp, "line_{$i}_{$j}\n");
        }
        fclose($fp);
        return $i;
    });
}

[$results, $exceptions] = await_all($coroutines);
sort($results);
echo "Completed: " . implode(',', $results) . "\n";
echo "Exceptions: " . count($exceptions) . "\n";

// Verify each file
foreach ($files as $i => $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "File $i: " . count($lines) . " lines\n";
    echo "First: {$lines[0]}\n";
    echo "Last: {$lines[99]}\n";
    unlink($file);
}

echo "End\n";

?>
--EXPECT--
Start
Completed: 0,1,2
Exceptions: 0
File 0: 100 lines
First: line_0_0
Last: line_0_99
File 1: 100 lines
First: line_1_0
Last: line_1_99
File 2: 100 lines
First: line_2_0
Last: line_2_99
End
