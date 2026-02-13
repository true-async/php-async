--TEST--
iterate() - Traversable object (ArrayIterator)
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

echo "start\n";

spawn(function() {
    $iter = new ArrayIterator(['x' => 10, 'y' => 20, 'z' => 30]);

    iterate($iter, function($value, $key) {
        echo "$key => $value\n";
    });

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
x => 10
y => 20
z => 30
done
