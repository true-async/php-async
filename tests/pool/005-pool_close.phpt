--TEST--
Pool: close - closes pool and destroys idle resources
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    destructor: function($r) {
        echo "Destroyed: $r\n";
    },
    min: 2,
    max: 5
);

echo "Created with min=2\n";
echo "Idle: " . $pool->idleCount() . "\n";
echo "Is closed: " . ($pool->isClosed() ? "yes" : "no") . "\n";

$pool->close();
echo "Closed\n";
echo "Is closed: " . ($pool->isClosed() ? "yes" : "no") . "\n";

echo "Done\n";
?>
--EXPECT--
Created with min=2
Idle: 2
Is closed: no
Destroyed: 1
Destroyed: 2
Closed
Is closed: yes
Done
