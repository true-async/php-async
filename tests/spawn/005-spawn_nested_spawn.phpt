--TEST--
Future: spawn() - nested spawns
--FILE--
<?php

use function Async\spawn;

echo "start\n";

spawn(function() {
    echo "outer coroutine start\n";
    
    spawn(function() {
        echo "inner coroutine 1\n";
    });
    
    spawn(function() {
        echo "inner coroutine 2\n";
    });
    
    echo "outer coroutine end\n";
});

echo "end\n";
?>
--EXPECT--
start
end
outer coroutine start
outer coroutine end
inner coroutine 1
inner coroutine 2