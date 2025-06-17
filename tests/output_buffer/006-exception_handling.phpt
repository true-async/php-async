--TEST--
Output Buffer: Exception handling with ob_start
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    ob_start();
    echo "Before exception";
    
    suspend(); // Context switch
    
    try {
        throw new Exception("Test exception");
    } catch (Exception $e) {
        echo " Caught: " . $e->getMessage();
    }
    
    $content = ob_get_contents();
    ob_end_clean();
    echo "Got: '$content'\n";
});

spawn(function() {
    ob_start();
    echo "Normal coroutine";
    
    suspend(); // Context switch
    
    echo " continues";
    $content = ob_get_contents();
    ob_end_clean();
    echo "Got: '$content'\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Got: 'Before exception Caught: Test exception'
Got: 'Normal coroutine continues'