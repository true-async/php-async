--TEST--
Scope::disposeAfterTimeout(): the timer of a scope that finished does not keep the process alive
--EXTENSIONS--
true_async
--FILE--
<?php

use Async\Scope;
use function Async\delay;

$start = hrtime(true);
register_shutdown_function(function () use ($start) {
    $ms = (hrtime(true) - $start) / 1e6;
    echo $ms < 1500 ? "ended without waiting for the timer\n" : "waited {$ms} ms for the timer\n";
});

$scope = new Scope();
$scope->spawn(function () {
    delay(10);
});
$scope->disposeAfterTimeout(3000);
echo "end\n";
?>
--EXPECT--
end
ended without waiting for the timer
