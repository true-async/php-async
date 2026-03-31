--TEST--
await_all() with already completed coroutine that threw exception does not deadlock
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all;

echo "start\n";

$failed = spawn(function() {
    throw new RuntimeException("already failed");
});

// Await to let it finish and handle the exception
try {
    await($failed);
} catch (RuntimeException $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

echo "failed: " . ($failed->isCompleted() ? "true" : "false") . "\n";

$pending = spawn(function() {
    echo "pending: done\n";
    return "success";
});

[$results, $errors] = await_all([$failed, $pending]);

echo "results count: " . count($results) . "\n";
echo "results[1]: " . $results[1] . "\n";
echo "errors count: " . count($errors) . "\n";
echo "error[0]: " . $errors[0]->getMessage() . "\n";
echo "end\n";

?>
--EXPECT--
start
caught: already failed
failed: true
pending: done
results count: 1
results[1]: success
errors count: 1
error[0]: already failed
end
