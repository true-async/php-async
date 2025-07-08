--TEST--
spawnWith() - invalid scope provider type error
--FILE--
<?php

use function Async\spawnWith;

echo "start\n";

class InvalidTypeScopeProvider implements \Async\ScopeProvider
{
    public function provideScope(): ?\Async\Scope
    {
        return "invalid"; // Should return Scope or null
    }
}

try {
    $coroutine = spawnWith(new InvalidTypeScopeProvider(), function() {
        return "test";
    });
    echo "ERROR: Should have thrown exception\n";
} catch (\Async\AsyncException $e) {
    echo "Caught expected exception: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Caught exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
Caught exception: TypeError: InvalidTypeScopeProvider::provideScope(): Return value must be of type ?Async\Scope, string returned
end