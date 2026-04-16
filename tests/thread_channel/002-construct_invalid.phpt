--TEST--
ThreadChannel: construct - invalid capacity throws error
--FILE--
<?php

use Async\ThreadChannel;

try {
    new ThreadChannel(0);
} catch (\ValueError $e) {
    echo "Zero: " . $e->getMessage() . "\n";
}

try {
    new ThreadChannel(-5);
} catch (\ValueError $e) {
    echo "Negative: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Zero: Async\ThreadChannel::__construct(): Argument #1 ($capacity) must be >= 1
Negative: Async\ThreadChannel::__construct(): Argument #1 ($capacity) must be >= 1
Done
