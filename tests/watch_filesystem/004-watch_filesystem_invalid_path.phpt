--TEST--
watch_filesystem() - error on invalid path
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\watch_filesystem;

spawn(function() {
    try {
        $future = watch_filesystem('/nonexistent/path/that/does/not/exist');
        echo "unexpected: future created\n";
    } catch (\Throwable $e) {
        echo "error caught: " . get_class($e) . "\n";
    }
});

echo "done\n";
?>
--EXPECT--
done
error caught: Async\AsyncException
