--TEST--
Future: no warning when ignore() is called
--FILE--
<?php

use Async\FutureState;
use Async\Future;

$state = new FutureState();
$future = new Future($state);
$future->ignore();
$state->complete("value");

unset($future, $state);

echo "Done\n";

?>
--EXPECT--
Done
