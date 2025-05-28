--TEST--
Future: spawn() - closure vs function name
--FILE--
<?php

use function Async\spawn;

function test_function() {
    echo "named function executed\n";
}

echo "start\n";

// Test with closure
spawn(function() {
    echo "closure executed\n";
});

// Test with function name
spawn('test_function');

echo "end\n";
?>
--EXPECTF--
start
end
closure executed
named function executed