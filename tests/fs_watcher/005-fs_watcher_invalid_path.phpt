--TEST--
FileSystemWatcher - invalid path throws exception
--FILE--
<?php

use Async\FileSystemWatcher;
use function Async\spawn;

spawn(function() {
    try {
        $watcher = new FileSystemWatcher('/nonexistent/path/that/does/not/exist');
        echo "ERROR: should have thrown\n";
    } catch (\Throwable $e) {
        echo "caught\n";
    }
    echo "done\n";
});
?>
--EXPECT--
caught
done
