--TEST--
iterate() - stop iteration when callback returns false
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

echo "start\n";

spawn(function() {
    $data = [1, 2, 3, 4, 5];

    iterate($data, function($value, $key) {
        echo "value: $value\n";

        if ($value === 3) {
            return false;
        }
    });

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
value: 1
value: 2
value: 3
done
