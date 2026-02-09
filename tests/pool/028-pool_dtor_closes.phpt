--TEST--
Pool: destructor - destroying pool closes it and frees resources
--FILE--
<?php

use Async\Pool;

function createPool() {
    $pool = new Pool(
        factory: function() {
            static $c = 0;
            $id = ++$c;
            echo "Created: $id\n";
            return $id;
        },
        destructor: function($r) {
            echo "Destroyed: $r\n";
        },
        min: 2
    );
    echo "Pool in function\n";
    // Pool destroyed when function returns
}

echo "Before function\n";
createPool();
echo "After function\n";

echo "Done\n";
?>
--EXPECT--
Before function
Created: 1
Created: 2
Pool in function
Destroyed: 1
Destroyed: 2
After function
Done
