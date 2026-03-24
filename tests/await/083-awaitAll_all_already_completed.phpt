--TEST--
await_all() with all awaitables already completed does not deadlock
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all;

echo "start\n";

$a = spawn(function() {
    return "aaa";
});

$b = spawn(function() {
    return "bbb";
});

// Let both finish
await($a);
await($b);

echo "a completed: " . ($a->isCompleted() ? "true" : "false") . "\n";
echo "b completed: " . ($b->isCompleted() ? "true" : "false") . "\n";

[$results, $errors] = await_all([$a, $b]);

echo "results[0]: " . $results[0] . "\n";
echo "results[1]: " . $results[1] . "\n";
echo "errors: " . count($errors) . "\n";
echo "end\n";

?>
--EXPECT--
start
a completed: true
b completed: true
results[0]: aaa
results[1]: bbb
errors: 0
end
