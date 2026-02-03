--TEST--
Pool: closure callbacks - all callbacks work with closures
--FILE--
<?php

use Async\Pool;

$log = [];

$pool = new Pool(
    factory: function() use (&$log) {
        static $c = 0;
        $id = ++$c;
        $log[] = "factory($id)";
        return $id;
    },
    destructor: function($r) use (&$log) {
        $log[] = "destructor($r)";
    },
    beforeAcquire: function($r) use (&$log) {
        $log[] = "beforeAcquire($r)";
        return true;
    },
    beforeRelease: function($r) use (&$log) {
        $log[] = "beforeRelease($r)";
        return true;
    },
    min: 1,
    max: 2
);

$log[] = "--- acquire ---";
$r = $pool->tryAcquire();

$log[] = "--- release ---";
$pool->release($r);

$log[] = "--- close ---";
$pool->close();

foreach ($log as $entry) {
    echo "$entry\n";
}

echo "Done\n";
?>
--EXPECT--
factory(1)
--- acquire ---
beforeAcquire(1)
--- release ---
beforeRelease(1)
--- close ---
destructor(1)
Done
