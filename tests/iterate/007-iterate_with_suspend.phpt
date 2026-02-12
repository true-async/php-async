--TEST--
iterate() - callback with suspend (async behavior)
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\iterate;

echo "start\n";

spawn(function() {
    $results = [];

    iterate([1, 2, 3], function($value, $key) use (&$results) {
        suspend();
        $results[] = $value * 10;
    });

    echo implode(',', $results) . "\n";
    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
10,20,30
done
