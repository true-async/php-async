--TEST--
Scope::disposeAfterTimeout(): a scope with only empty child scopes does not keep the process alive
--EXTENSIONS--
true_async
--FILE--
<?php

use Async\Scope;

// Child scopes without coroutines leave nothing for the timer to cancel.
$start = hrtime(true);
$parent = new Scope();
$empty = Scope::inherit($parent);
$parent->disposeAfterTimeout(3000);
register_shutdown_function(function () use ($start) {
    $ms = (hrtime(true) - $start) / 1e6;
    echo $ms < 1500 ? "ended without waiting\n" : "waited {$ms} ms\n";
});
?>
--EXPECT--
ended without waiting
