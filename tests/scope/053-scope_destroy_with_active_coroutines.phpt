--TEST--
Scope: destroying scope object while coroutines are still active cancels them
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:1387-1391 — scope_destroy() try_to_dispose-returns-false branch
// that issues a "Scope is being disposed due to object destruction" cancellation.

echo "start\n";

function make_scope_with_coroutine() {
    $scope = new Scope();
    $scope->asNotSafely();
    $scope->spawn(function () {
        try {
            while (true) {
                suspend();
            }
        } catch (\Async\AsyncCancellation $e) {
            echo "inner got: " . $e->getMessage() . "\n";
        }
    });
    suspend(); // let the inner coroutine actually start running
    // $scope goes out of scope when this function returns.
    // Its destructor must cancel the running coroutine.
}

make_scope_with_coroutine();

// Let the scheduler dispatch the cancellation before exiting.
suspend();
suspend();

echo "end\n";

?>
--EXPECTF--
start
inner got: Scope is being disposed due to object destruction
end

