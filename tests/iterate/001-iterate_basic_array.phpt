--TEST--
iterate() - basic array iteration
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

echo "start\n";

spawn(function() {
    $data = ['a' => 1, 'b' => 2, 'c' => 3];

    iterate($data, function($value, $key) {
        echo "$key => $value\n";
    });

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
a => 1
b => 2
c => 3
done
