--TEST--
Pool: a wrapper carrying no pool throws instead of dereferencing NULL
--EXTENSIONS--
true_async
--FILE--
<?php

use Async\Pool;

/* unserialize() builds the object without running the constructor, so obj->pool is NULL.
   Every method has to answer that, not read through it. */
$pool = unserialize('O:10:"Async\Pool":0:{}');

foreach (['getState', 'activate', 'deactivate', 'recover', 'close', 'isClosed'] as $method) {
    try {
        $pool->$method();
        echo "$method: returned\n";
    } catch (Async\PoolException $e) {
        echo "$method: ", $e->getMessage(), "\n";
    }
}

try {
    $pool->setCircuitBreakerStrategy(null);
    echo "setCircuitBreakerStrategy: returned\n";
} catch (Async\PoolException $e) {
    echo "setCircuitBreakerStrategy: ", $e->getMessage(), "\n";
}

echo count($pool), " ", $pool->idleCount(), " ", $pool->activeCount(), "\n";
echo "Done\n";
?>
--EXPECT--
getState: Pool is not initialized
activate: Pool is not initialized
deactivate: Pool is not initialized
recover: Pool is not initialized
close: returned
isClosed: returned
setCircuitBreakerStrategy: Pool is not initialized
0 0 0
Done
