--TEST--
spawnWith() - scope provider returns null (valid case)
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;

echo "start\n";

class NullScopeProvider implements \Async\ScopeProvider
{
    public function provideScope(): ?\Async\Scope
    {
        return null; // Valid - should use inherited scope
    }
}

try {
    $coroutine = spawnWith(new NullScopeProvider(), function() {
        return "success";
    });

    echo "Null provider result: " . await($coroutine) . "\n";
} catch (Throwable $e) {
    echo "Unexpected exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Null provider result: success
end