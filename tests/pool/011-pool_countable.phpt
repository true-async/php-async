--TEST--
Pool: Countable interface
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    min: 2,
    max: 5
);

echo "Initial count(): " . count($pool) . "\n";
echo "instanceof Countable: " . ($pool instanceof Countable ? "yes" : "no") . "\n";

$r = $pool->tryAcquire();
echo "After acquire count(): " . count($pool) . "\n";

$pool->release($r);
echo "After release count(): " . count($pool) . "\n";

echo "Done\n";
?>
--EXPECT--
Initial count(): 2
instanceof Countable: yes
After acquire count(): 2
After release count(): 2
Done
