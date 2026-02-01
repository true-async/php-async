--TEST--
Future: no warning when exception is ignored
--FILE--
<?php

use Async\FutureState;
use Async\Future;

$state = new FutureState();
$future = new Future($state);
$future->ignore();
$state->error(new Exception("Test error"));

unset($future, $state);

echo "Done\n";

?>
--EXPECT--
Done
