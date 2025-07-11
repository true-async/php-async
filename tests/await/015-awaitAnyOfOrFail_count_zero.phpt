--TEST--
awaitAnyOfOrFail() - count is zero
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfOrFail;

echo "start\n";

$coroutines = [
    spawn(function() {
        return "first";
    }),
    spawn(function() {
        return "second";
    }),
];

$results = awaitAnyOfOrFail(0, $coroutines);
var_dump($results);

echo "end\n";
?>
--EXPECT--
start
array(0) {
}
end