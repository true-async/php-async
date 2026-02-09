--TEST--
stream_copy_to_stream works in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$src_file = tempnam(sys_get_temp_dir(), 'async_io_src_');
$dst_file = tempnam(sys_get_temp_dir(), 'async_io_dst_');
file_put_contents($src_file, "ABCDEFGHIJKLMNOP");

$coroutine = spawn(function() use ($src_file, $dst_file) {
    $src = fopen($src_file, 'r');
    $dst = fopen($dst_file, 'w');

    // Copy full
    $copied = stream_copy_to_stream($src, $dst);
    echo "Copied: $copied\n";

    fclose($src);
    fclose($dst);

    // Verify
    $data = file_get_contents($dst_file);
    echo "Data: '$data'\n";

    // Copy with maxlength and offset
    $src = fopen($src_file, 'r');
    $dst = fopen($dst_file, 'w');
    $copied2 = stream_copy_to_stream($src, $dst, 5, 3);
    echo "Partial copy: $copied2\n";

    fclose($src);
    fclose($dst);

    $data2 = file_get_contents($dst_file);
    echo "Partial data: '$data2'\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($src_file);
unlink($dst_file);
echo "End\n";

?>
--EXPECT--
Start
Copied: 16
Data: 'ABCDEFGHIJKLMNOP'
Partial copy: 5
Partial data: 'DEFGH'
Result: done
End
