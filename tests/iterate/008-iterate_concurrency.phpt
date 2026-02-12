--TEST--
iterate() - concurrency parameter limits parallel coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\iterate;

echo "start\n";

spawn(function() {
    $active = 0;
    $max_active = 0;

    iterate([1, 2, 3, 4, 5], function($value, $key) use (&$active, &$max_active) {
        $active++;

        if ($active > $max_active) {
            $max_active = $active;
        }

        suspend();
        $active--;
    }, concurrency: 2);

    echo "max active: $max_active\n";
    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
max active: 2
done
