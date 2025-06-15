--TEST--
Output Buffer: ob_flush and ob_clean isolation
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    ob_start();
    echo "Before flush\n";
    
    suspend(); // Context switch
    
    ob_flush(); // Should flush only this coroutine's buffer
    echo " After flush";
    
    suspend(); // Another context switch
    
    $content = ob_get_contents();
    ob_end_clean();
    echo "Remaining: '$content'\n";
});

spawn(function() {
    ob_start();
    echo "Other coroutine";
    
    suspend(); // Context switch
    
    ob_clean(); // Should clean only this coroutine's buffer
    echo "After clean";
    
    $content = ob_get_contents();
    ob_end_clean();
    echo "Got: '$content'\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Before flush
Got: 'After clean'
Remaining: ' After flush'