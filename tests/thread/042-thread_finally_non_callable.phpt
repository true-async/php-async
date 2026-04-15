--TEST--
Thread: finally() rejects non-callable argument
--FILE--
<?php

use function Async\spawn_thread;
use function Async\await;

// Covers thread.c METHOD(finally) L2309-2311: non-callable argument
// → async_throw_error("Argument #1 ($callback) must be callable").

$t = spawn_thread(function() { return 1; });
try {
    $t->finally("definitely not a function");
} catch (\Async\AsyncException $e) {
    echo "non-callable: ", $e->getMessage(), "\n";
}
await($t);

echo "end\n";

?>
--EXPECT--
non-callable: Argument #1 ($callback) must be callable
end
