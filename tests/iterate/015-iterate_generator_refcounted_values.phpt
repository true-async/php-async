--TEST--
iterate() - generator yielding freshly-allocated strings (regression: double-dtor of fci.params[0])
--FILE--
<?php

use function Async\spawn;
use function Async\iterate;

function gen(): Generator {
    for ($i = 0; $i < 5; $i++) {
        yield "key-" . $i => "value-" . $i;
    }
}

spawn(function() {
    iterate(gen(), function($value, $key) {
        echo "$key => $value\n";
    });

    echo "done\n";
});
?>
--EXPECT--
key-0 => value-0
key-1 => value-1
key-2 => value-2
key-3 => value-3
key-4 => value-4
done
