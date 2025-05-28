--TEST--
awaitAny() - empty iterable
--FILE--
<?php

use function Async\awaitAny;

echo "start\n";

$result = awaitAny([]);
var_dump($result);

echo "end\n";
?>
--EXPECT--
start
NULL
end