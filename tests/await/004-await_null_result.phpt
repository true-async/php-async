--TEST--
await() - coroutine returns null
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    echo "coroutine running\n";
    return null;
});

$result = await($coroutine);
var_dump($result);

echo "end\n";
?>
--EXPECT--
start
coroutine running
NULL
end