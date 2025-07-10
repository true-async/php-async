--TEST--
Async\protect: exception handling in protected closure
--FILE--
<?php

use function Async\protect;

echo "start\n";

try {
    protect(function() {
        echo "protected closure\n";
        throw new Exception("Exception");
    });
} catch (Exception $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
protected closure
caught exception: Exception
end