--TEST--
Pool: double close - second close is no-op
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: fn() => 1,
    destructor: function($r) {
        echo "Destroyed: $r\n";
    },
    min: 1
);

echo "Is closed: " . ($pool->isClosed() ? "yes" : "no") . "\n";

$pool->close();
echo "After first close: " . ($pool->isClosed() ? "yes" : "no") . "\n";

$pool->close();
echo "After second close: " . ($pool->isClosed() ? "yes" : "no") . "\n";

echo "Done\n";
?>
--EXPECT--
Is closed: no
Destroyed: 1
After first close: yes
After second close: yes
Done
