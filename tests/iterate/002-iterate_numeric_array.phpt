--TEST--
iterate() - numeric array with integer keys
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

echo "start\n";

spawn(function() {
    $data = [10, 20, 30, 40];

    iterate($data, function($value, $key) {
        echo "$key: $value\n";
    });

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
0: 10
1: 20
2: 30
3: 40
done
