--TEST--
GC 023: a collection that found the buffer already drained does not adjust the threshold
--INI--
zend.enable_gc=1
--FILE--
<?php

use function Async\await;
use function Async\delay;
use function Async\spawn;

// The buffer fills with objects that are alive but suspected of cycles, which
// hands a collection to the GC coroutine. They are then released outright, so
// the run finds an empty buffer and examines nothing. Its zero says nothing
// about how much garbage there is, and the threshold must not move on it.
await(spawn(function () {
    $keep = [];

    for ($i = 1; $i <= 11000; $i++) {
        $o = new stdClass();
        $keep[$i] = $o;
        unset($o);
    }

    $keep = null;

    delay(1);
}));

$status = gc_status();

echo "roots: ", $status['roots'], "\n";
echo "collected: ", $status['collected'], "\n";
echo "threshold: ", $status['threshold'], "\n";

?>
--EXPECT--
roots: 0
collected: 0
threshold: 10001
