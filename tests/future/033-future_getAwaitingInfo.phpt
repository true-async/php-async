--TEST--
Future: getAwaitingInfo() returns single-element array with FutureState info string
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

// Covers future.c FUTURE_METHOD(getAwaitingInfo) on Future (1756-1774).

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $info = $future->getAwaitingInfo();
    var_dump(is_array($info));
    var_dump(count($info));
    // Entry is a non-empty string produced by zend_future_info()
    var_dump(is_string($info[0]) && strlen($info[0]) > 0);
    echo "contains pending: ", (strpos($info[0], "pending") !== false ? "yes" : "no"), "\n";

    $state->complete(42);
    $info = $future->getAwaitingInfo();
    echo "contains completed: ", (strpos($info[0], "completed") !== false ? "yes" : "no"), "\n";

    $future->ignore();
});

await($coroutine);

?>
--EXPECT--
bool(true)
int(1)
bool(true)
contains pending: yes
contains completed: yes
