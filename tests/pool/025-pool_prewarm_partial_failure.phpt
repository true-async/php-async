--TEST--
Pool: prewarm partial failure - stops on first factory exception
--FILE--
<?php

use Async\Pool;

$created = 0;

try {
    $pool = new Pool(
        factory: function() use (&$created) {
            $created++;
            echo "Creating: $created\n";
            if ($created >= 2) {
                throw new RuntimeException("Factory failed at $created");
            }
            return $created;
        },
        min: 5,
        max: 10
    );
} catch (RuntimeException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Total created: $created\n";

echo "Done\n";
?>
--EXPECT--
Creating: 1
Creating: 2
Caught: Factory failed at 2
Total created: 2
Done
