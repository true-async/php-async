--TEST--
Future: warning when exception is not caught
--FILE--
<?php

use Async\FutureState;
use Async\Future;

$state = new FutureState();
$future = new Future($state);

// Use finally to mark as USED (finally doesn't catch exceptions)
$chained = $future->finally(fn() => null);
$chained->ignore();

$state->error(new Exception("Test error message"));

unset($future, $state, $chained);

echo "Done\n";

?>
--EXPECTF--
Done

Warning: Unhandled exception in Future: Test error message; use catch() or ignore() to handle. Created at %s:%d, completed at %s:%d in %s on line %d
