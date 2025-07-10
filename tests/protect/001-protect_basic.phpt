--TEST--
Async\protect: basic usage
--FILE--
<?php

use function Async\protect;

echo "start\n";

protect(function() {
    echo "protected block\n";
});

echo "end\n";

?>
--EXPECT--
start
protected block
end