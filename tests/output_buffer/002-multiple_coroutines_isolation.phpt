--TEST--
Output Buffer: Multiple coroutines with independent buffers
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    ob_start();
    echo "Coroutine A: part1";
    
    suspend(); // Switch context
    
    echo " part2";
    $content = ob_get_contents();
    ob_end_clean();
    echo "A got: '$content'\n";
});

spawn(function() {
    ob_start();
    echo "Coroutine B: part1";
    
    suspend(); // Switch context
    
    echo " part2";
    $content = ob_get_contents();
    ob_end_clean();
    echo "B got: '$content'\n";
});

spawn(function() {
    ob_start();
    echo "Coroutine C: part1";
    
    suspend(); // Switch context
    
    echo " part2";
    $content = ob_get_contents();
    ob_end_clean();
    echo "C got: '$content'\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
A got: 'Coroutine A: part1 part2'
B got: 'Coroutine B: part1 part2'
C got: 'Coroutine C: part1 part2'