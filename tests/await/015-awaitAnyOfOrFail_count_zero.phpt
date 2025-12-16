--TEST--
await_any_of_or_fail() - count is zero
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of_or_fail;

echo "start\n";

$coroutines = [
    spawn(function() {
        return "first";
    }),
    spawn(function() {
        return "second";
    }),
];

$results = await_any_of_or_fail(0, $coroutines);
var_dump($results);

echo "end\n";
?>
--EXPECT--
start
array(0) {
}
end