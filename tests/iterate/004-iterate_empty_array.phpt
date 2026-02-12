--TEST--
iterate() - empty array
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

echo "start\n";

spawn(function() {
    iterate([], function($value, $key) {
        echo "should not be called\n";
    });

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
done
