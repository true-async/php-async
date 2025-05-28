--TEST--
await() - basic usage with coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    echo "coroutine running\n";
    return "result";
});

$result = await($coroutine);
echo "awaited result: $result\n";

echo "end\n";
?>
--EXPECT--
start
coroutine running
awaited result: result
end