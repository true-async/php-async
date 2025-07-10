--TEST--
spawnWith() - scope provider throws exception
--FILE--
<?php

use function Async\spawnWith;

echo "start\n";

class ThrowingScopeProvider implements \Async\ScopeProvider
{
    public function provideScope(): ?\Async\Scope
    {
        throw new \RuntimeException("Provider error");
    }
}

try {
    $coroutine = spawnWith(new ThrowingScopeProvider(), function() {
        return "test";
    });
    echo "ERROR: Should have thrown exception\n";
} catch (\RuntimeException $e) {
    echo "Caught provider exception: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Caught exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Caught provider exception: Provider error
end