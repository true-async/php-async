--TEST--
Async\spawnWith: basic usage with Scope
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\Scope;

echo "start\n";

$scope = new Scope();

$coroutine = spawnWith($scope, function() {
    echo "coroutine executed\n";
    return "test result";
});

echo "spawned coroutine\n";

$result = await($coroutine);
echo "result: $result\n";

echo "end\n";

?>
--EXPECT--
start
spawned coroutine
coroutine executed
result: test result
end