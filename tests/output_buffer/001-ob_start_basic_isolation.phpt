--TEST--
Output Buffer: Basic ob_start isolation with suspend
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Main starts\n";

ob_start();
echo "Main buffer before";

spawn(function() {
    echo "Coroutine starts\n";
    
    ob_start();
    echo "Before suspend";
    
    suspend(); // Coroutine loses control
    
    echo " After suspend";
    $content = ob_get_contents();
    ob_end_clean();
    
    echo "Got: '$content'\n";
});

suspend(); // Main also loses control

echo " Main buffer after";
$main_content = ob_get_contents();
ob_end_clean();

echo "Main got: '$main_content'\n";

?>
--EXPECT--
Main starts
Coroutine starts
Main got: 'Main buffer before Main buffer after'
Got: 'Before suspend After suspend'