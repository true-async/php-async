--TEST--
Future: no warning when await() is called
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use function Async\spawn;

spawn(function() {
    $state = new FutureState();
    $future = new Future($state);
    $state->complete("value");

    // await() marks future as used
    echo $future->await() . "\n";
});

echo "Done\n";

?>
--EXPECT--
Done
value
