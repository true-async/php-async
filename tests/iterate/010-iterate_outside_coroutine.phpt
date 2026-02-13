--TEST--
iterate() - called outside coroutine starts scheduler automatically
--FILE--
<?php

use function Async\iterate;

echo "start\n";

iterate([1, 2, 3], function($value, $key) {
    echo "value: $value\n";
});

echo "done\n";
?>
--EXPECT--
start
value: 1
value: 2
value: 3
done
