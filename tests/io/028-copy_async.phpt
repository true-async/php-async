--TEST--
copy() works in async context through stream layer
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$src = tempnam(sys_get_temp_dir(), 'async_io_src_');
$dst = tempnam(sys_get_temp_dir(), 'async_io_dst_');
file_put_contents($src, str_repeat("COPY_DATA_", 100));

$coroutine = spawn(function() use ($src, $dst) {
    $result = copy($src, $dst);
    echo "Copy result: " . ($result ? "true" : "false") . "\n";

    $src_data = file_get_contents($src);
    $dst_data = file_get_contents($dst);
    echo "Match: " . ($src_data === $dst_data ? "yes" : "no") . "\n";
    echo "Size: " . strlen($dst_data) . "\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($src);
unlink($dst);
echo "End\n";

?>
--EXPECT--
Start
Copy result: true
Match: yes
Size: 1000
Result: done
End
