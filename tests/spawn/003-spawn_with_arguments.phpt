--TEST--
Future: spawn() - coroutine with arguments
--FILE--
<?php

use function Async\spawn;

echo "start\n";

spawn(function($a, $b, $c) {
    echo "coroutine: $a, $b, $c\n";
}, "hello", 42, true);

spawn(function(...$args) {
    echo "variadic: " . implode(", ", $args) . "\n";
}, "arg1", "arg2", "arg3");

echo "end\n";
?>
--EXPECT--
start
end
coroutine: hello, 42, 1
variadic: arg1, arg2, arg3