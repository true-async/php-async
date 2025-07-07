--TEST--
Async\spawnWith: with arguments
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\Scope;

echo "start\n";

$scope = new Scope();

$coroutine = spawnWith($scope, function($a, $b, $c) {
    echo "arguments: $a, $b, $c\n";
    return $a + $b + $c;
}, 10, 20, 30);

$result = await($coroutine);
echo "result: $result\n";

echo "end\n";

?>
--EXPECT--
start
arguments: 10, 20, 30
result: 60
end