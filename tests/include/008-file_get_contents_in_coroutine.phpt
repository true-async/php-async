--TEST--
file_get_contents and file_put_contents inside coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$tmpFile = tempnam(sys_get_temp_dir(), 'async_test_');

$c1 = spawn(function() use ($tmpFile) {
    file_put_contents($tmpFile, "hello async world");
    $content = file_get_contents($tmpFile);
    echo "read: $content\n";
});

await($c1);

@unlink($tmpFile);
echo "done\n";
?>
--EXPECT--
read: hello async world
done
