--TEST--
await_any_or_fail() - empty iterable
--FILE--
<?php

use function Async\await_any_or_fail;

echo "start\n";

$result = await_any_or_fail([]);

$resultCheck = $result === null ? "OK" : "FALSE: " . var_export($result, true);
echo "Result is null: $resultCheck\n";

echo "end\n";
?>
--EXPECT--
start
Result is null: OK
end