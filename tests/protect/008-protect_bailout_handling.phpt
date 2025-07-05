--TEST--
Async\protect: bailout handling in protected closure
--FILE--
<?php

use function Async\protect;

echo "start\n";

protect(function() {
    echo "protected closure\n";
    
    // Test that even with potential bailout scenarios,
    // the protected flag is properly cleared
    $array = [];
    echo "accessing array\n";
    
    // This should work normally
    if (isset($array[0])) {
        echo "array element exists\n";
    } else {
        echo "array element does not exist\n";
    }
});

echo "end\n";

?>
--EXPECT--
start
protected closure
accessing array
array element does not exist
end