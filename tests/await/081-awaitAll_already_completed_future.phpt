--TEST--
await_all() with already completed Future does not deadlock
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await_all;

echo "start\n";

$state = new FutureState();
$state->complete("future_result");
$completed_future = new Future($state);

$pending = spawn(function() {
    echo "pending: done\n";
    return "coroutine_result";
});

[$results, $errors] = await_all([$completed_future, $pending]);

echo "results[0]: " . $results[0] . "\n";
echo "results[1]: " . $results[1] . "\n";
echo "errors: " . count($errors) . "\n";
echo "end\n";

?>
--EXPECT--
start
pending: done
results[0]: future_result
results[1]: coroutine_result
errors: 0
end
