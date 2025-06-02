--TEST--
awaitAll() - test for double free issue with many coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "start\n";

$coroutines = [];

// create multiple coroutines that will return values after a delay
for ($i = 1; $i <= 100; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        delay($i);
        return "coroutine $i";
    });
}

$results = awaitAll($coroutines);

foreach ($results as $result) {
    unset($result); // intentionally unset to trigger double free
}

echo "end\n";
?>
--EXPECT--
start
end