--TEST--
iterate() - Generator as iterable source
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

function gen(): Generator {
    yield 'a' => 100;
    yield 'b' => 200;
    yield 'c' => 300;
}

echo "start\n";

spawn(function() {
    iterate(gen(), function($value, $key) {
        echo "$key => $value\n";
    });

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
a => 100
b => 200
c => 300
done
