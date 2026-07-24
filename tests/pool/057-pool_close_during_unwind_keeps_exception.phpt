--TEST--
Pool: close during stack unwinding must not steal the in-flight exception
--EXTENSIONS--
true_async
--FILE--
<?php

use Async\Pool;

// A local pool destroyed while an exception unwinds the frame closes itself;
// the destroy loop must not take that exception for a resource dtor's failure.

function boom(): void
{
    $pool = new Pool(
        factory: fn () => 1,
        min: 1,
        max: 2,
    );

    throw new RuntimeException('boom');
}

try {
    boom();
} catch (Exception $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}

echo "done\n";

?>
--EXPECT--
caught: boom
done
