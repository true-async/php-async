--TEST--
awaitAny() - empty iterable
--FILE--
<?php

use function Async\awaitAny;

echo "start\n";

$result = awaitAny([]);

$resultCheck = $result === null ? "OK" : "FALSE: " . var_export($result, true);
echo "Result is null: $resultCheck\n";

echo "end\n";
?>
--EXPECT--
start
Result is null: OK
end