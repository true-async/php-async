--TEST--
Async\protect: exception thrown inside protected closure
--FILE--
<?php

use function Async\protect;

echo "start\n";

try {
    protect(function() {
        echo "before exception\n";
        throw new RuntimeException("test exception");
        echo "after exception\n"; // This should not be reached
    });
} catch (RuntimeException $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
before exception
caught exception: test exception
end