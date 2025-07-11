--TEST--
awaitAnyOrFail() - empty iterable
--FILE--
<?php

use function Async\awaitAnyOrFail;

echo "start\n";

$result = awaitAnyOrFail([]);

$resultCheck = $result === null ? "OK" : "FALSE: " . var_export($result, true);
echo "Result is null: $resultCheck\n";

echo "end\n";
?>
--EXPECT--
start
Result is null: OK
end