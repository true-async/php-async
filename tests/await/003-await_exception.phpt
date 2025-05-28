--TEST--
await() - coroutine throws exception
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    echo "coroutine running\n";
    throw new RuntimeException("test exception");
});

try {
    $result = await($coroutine);
    echo "awaited result: $result\n";
} catch (RuntimeException $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";
?>
--EXPECT--
start
coroutine running
caught exception: test exception
end