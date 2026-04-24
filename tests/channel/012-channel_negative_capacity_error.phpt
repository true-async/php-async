--TEST--
Channel: negative capacity throws error
--FILE--
<?php

use Async\Channel;

try {
    $ch = new Channel(-1);
    echo "ERROR: should have thrown\n";
} catch (ValueError $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECTF--
Caught: %s must be between 0 and 2147483647
Done
