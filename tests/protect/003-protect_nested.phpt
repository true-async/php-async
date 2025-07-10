--TEST--
Async\protect: nested protect calls
--FILE--
<?php

use function Async\protect;

echo "start\n";

protect(function() {
    echo "outer protect start\n";
    
    protect(function() {
        echo "inner protect\n";
    });
    
    echo "outer protect end\n";
});

echo "end\n";

?>
--EXPECT--
start
outer protect start
inner protect
outer protect end
end