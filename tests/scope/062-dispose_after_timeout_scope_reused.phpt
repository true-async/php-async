--TEST--
Scope::disposeAfterTimeout(): the deadline holds for a coroutine spawned after the scope ran empty
--EXTENSIONS--
true_async
--FILE--
<?php

use Async\Scope;
use function Async\await;
use function Async\delay;
use function Async\spawn;

// The timer stops while the scope is empty, so the process can end; a
// coroutine spawned into the scope (or a child scope) later still gets the
// deadline set before, not a fresh one.

foreach (['the scope' => false, 'a child scope' => true] as $label => $child) {
    $scope = new Scope();
    $target = $child ? Scope::inherit($scope) : $scope;
    $start = hrtime(true);
    $scope->spawn(fn () => delay(10));
    $scope->disposeAfterTimeout(1000);

    await(spawn(fn () => delay(100)));   // the scope is empty by now

    $late = $target->spawn(function () {
        delay(10000);
        return "not cancelled";
    });

    try {
        $result = await($late);
        echo "$label: $result\n";
    } catch (Async\AsyncCancellation $e) {
        $ms = (hrtime(true) - $start) / 1e6;
        echo "$label: cancelled ", $ms < 5000 ? "at the deadline" : "after {$ms} ms", "\n";
    }
}

// Child scopes without coroutines leave nothing for the timer to cancel.
$start = hrtime(true);
$parent = new Scope();
$empty = Scope::inherit($parent);
$parent->disposeAfterTimeout(3000);
register_shutdown_function(function () use ($start) {
    $ms = (hrtime(true) - $start) / 1e6;
    echo $ms < 1500 ? "empty child scopes: ended without waiting\n" : "empty child scopes: waited {$ms} ms\n";
});
?>
--EXPECT--
the scope: cancelled at the deadline
a child scope: cancelled at the deadline
empty child scopes: ended without waiting
