--TEST--
Output Buffer: Mixed buffered and non-buffered output
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Main without buffer\n";

spawn(function() {
    echo "Coroutine 1 without buffer\n";
    
    ob_start();
    echo "Now with buffer";
    
    suspend(); // Context switch
    
    echo " continued";
    $content = ob_get_contents();
    ob_end_clean();
    
    echo "Buffered was: '$content'\n";
});

spawn(function() {
    ob_start();
    echo "Coroutine 2 always buffered";
    
    suspend(); // Context switch
    
    echo " until end";
    $content = ob_get_contents();
    ob_end_clean();
    
    echo "Got: '$content'\n";
});

echo "Main still without buffer\n";

?>
--EXPECT--
Main without buffer
Main still without buffer
Coroutine 1 without buffer
Buffered was: 'Now with buffer continued'
Got: 'Coroutine 2 always buffered until end'