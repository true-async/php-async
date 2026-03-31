--TEST--
await_all() with already completed coroutine does not deadlock
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

echo "start\n";

$completed = spawn(function() {
    echo "completed: done\n";
    return "already_done";
});

// Let it finish
suspend();

echo "completed: " . ($completed->isCompleted() ? "true" : "false") . "\n";

$pending = spawn(function() {
    echo "pending: done\n";
    return "just_finished";
});

[$results, $errors] = await_all([$completed, $pending]);

echo "results[0]: " . $results[0] . "\n";
echo "results[1]: " . $results[1] . "\n";
echo "errors: " . count($errors) . "\n";
echo "end\n";

?>
--EXPECT--
start
completed: done
completed: true
pending: done
results[0]: already_done
results[1]: just_finished
errors: 0
end
