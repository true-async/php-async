--TEST--
Future::map() - works with already completed future
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\Future;

$coroutine = spawn(function() {
    $completed = Future::completed(42);

    $mapped = $completed->map(function($x) {
        echo "Mapping: $x\n";
        return $x * 2;
    });

    return await($mapped);
});

echo "Result: " . await($coroutine) . "\n";

?>
--EXPECT--
Mapping: 42
Result: 84
