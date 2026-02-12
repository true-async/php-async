--TEST--
iterate() - exception in callback with concurrency cancels remaining coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\iterate;

echo "start\n";

spawn(function() {
    try {
        iterate([1, 2, 3, 4, 5], function($value, $key) {
            echo "begin: $value\n";
            suspend();

            if ($value === 2) {
                throw new RuntimeException("error at $value");
            }

            echo "end: $value\n";
        }, concurrency: 3);
    } catch (RuntimeException $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
begin: 1
begin: 2
begin: 3
end: 1
caught: error at 2
done
