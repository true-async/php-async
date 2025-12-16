--TEST--
await_all() - The object used to cancel the wait is simultaneously the object being awaited.
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "start\n";

$coroutine1 = spawn(function() {
    return "first";
});

$coroutine2 = spawn(function() {
    return "second";
});

try {
    $result = await_all([$coroutine1, $coroutine2], $coroutine2);
    var_dump($result);
} catch (\Async\CancellationError $e) {
}

echo "end\n";
?>
--EXPECTF--
start
end