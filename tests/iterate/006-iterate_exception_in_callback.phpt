--TEST--
iterate() - exception thrown in callback
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

echo "start\n";

spawn(function() {
    try {
        iterate([1, 2, 3], function($value, $key) {
            echo "value: $value\n";
            if ($value === 2) {
                throw new RuntimeException("error at $value");
            }
        });
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
value: 1
value: 2
caught: error at 2
done
