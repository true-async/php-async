--TEST--
awaitAny() - with ArrayObject
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
use function Async\await;
use function Async\suspend;

// We need to create functions instead of coroutines directly
// to ensure proper capturing by awaitAny when using ArrayObject
$functions = [
    function() { suspend(); return "slow"; },
    function() { return "fast"; },
    function() { suspend(); return "medium"; },
];

// Create coroutines from functions and wrap in ArrayObject
$coroutines = array_map(fn($func) => spawn($func), $functions);
$arrayObject = new ArrayObject($coroutines);

echo "start\n";

$result = awaitAny($arrayObject);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end