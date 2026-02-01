--TEST--
Future: warning when future is never used
--FILE--
<?php

use Async\FutureState;
use Async\Future;

$state = new FutureState();
$future = new Future($state);
$state->complete("value");

// Future is not used - should trigger warning on destruction
unset($future, $state);

echo "Done\n";

?>
--EXPECTF--
Warning: Future was never used; call await(), map(), catch(), finally() or ignore() to suppress this warning. Created at %s:%d in %s on line %d
Done
