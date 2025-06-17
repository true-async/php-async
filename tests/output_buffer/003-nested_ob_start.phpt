--TEST--
Output Buffer: Nested ob_start in coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    ob_start();
    echo "Level 1: ";
    
    ob_start();
    echo "Level 2: ";
    
    suspend(); // Context switch with nested buffers
    
    echo "content";
    $level2 = ob_get_contents();
    ob_end_clean();
    
    echo "Got level2: '$level2' ";
    $level1 = ob_get_contents();
    ob_end_clean();
    
    echo "Got level1: '$level1'\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Got level1: 'Level 1: Got level2: 'Level 2: content' '