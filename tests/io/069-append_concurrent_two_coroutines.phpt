--TEST--
Two coroutines appending to the same file do not corrupt each other's data
--XFAIL--
Windows: WriteFile ignores CRT _O_APPEND flag because FILE_WRITE_DATA is present alongside FILE_APPEND_DATA on the HANDLE. Removing FILE_WRITE_DATA would fix atomic append but breaks ftruncate (SetEndOfFile requires FILE_WRITE_DATA). The lseek(SEEK_END) workaround in the reactor is not sufficient when libuv dispatches writes to a worker thread — another coroutine can obtain the same EOF offset before the first write completes.
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, '');

$c1 = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a');
    // Write 4 chunks so the scheduler can interleave
    for ($i = 0; $i < 4; $i++) {
        fwrite($fp, "A$i");
    }
    fclose($fp);
});

$c2 = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a');
    for ($i = 0; $i < 4; $i++) {
        fwrite($fp, "B$i");
    }
    fclose($fp);
});

await($c1);
await($c2);

$content = file_get_contents($tmpfile);
$length = strlen($content);

// Each coroutine writes 4 * 2 = 8 bytes; total must be 16
echo "length: $length\n";

// Count A and B writes — each must appear exactly 4 times
$a_count = preg_match_all('/A\d/', $content);
$b_count = preg_match_all('/B\d/', $content);
echo "A writes: $a_count\n";
echo "B writes: $b_count\n";
echo "no corruption: " . ($length === 16 ? 'yes' : 'no') . "\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
length: 16
A writes: 4
B writes: 4
no corruption: yes
End
