--TEST--
Async\Timeout: direct construct forbidden, isCompleted/isCancelled/cancel methods
--FILE--
<?php

use Async\Timeout;
use function Async\timeout;
use function Async\delay;

// Covers async.c:1276-1316 — PHP_METHOD(Async_Timeout, __construct/cancel/isCompleted/isCancelled)
// and async.c:1429-1439 — async_timeout_object_create() factory.

echo "start\n";

// 1. Direct construction is forbidden
try {
    new Timeout();
} catch (\Throwable $e) {
    echo "construct: " . $e->getMessage() . "\n";
}

// 2. Created via Async\timeout() — initially not completed.
$t = timeout(5000);
var_dump($t instanceof Timeout);
var_dump($t->isCompleted());
var_dump($t->isCancelled());

// 3. cancel() releases the underlying timer.
$t->cancel();
var_dump($t->isCancelled());
var_dump($t->isCompleted());

// 4. cancel() on an already-cancelled Timeout is idempotent.
$t->cancel();
echo "double cancel ok\n";

echo "end\n";

?>
--EXPECTF--
start
construct: %A
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
double cancel ok
end
