--TEST--
DNS memory stress test with many concurrent lookups
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "DNS memory stress test\n";

$start_memory = memory_get_usage();
echo "Starting memory usage: " . number_format($start_memory) . " bytes\n";

$coroutines = [];
$lookup_count = 50;

// Create many concurrent DNS lookups
for ($i = 0; $i < $lookup_count; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        // Alternate between different lookups to test various code paths
        switch ($i % 4) {
            case 0:
                return gethostbyname('localhost');
            case 1:
                return gethostbyaddr('127.0.0.1');
            case 2:
                return gethostbynamel('localhost');
            case 3:
                return gethostbyname('127.0.0.1');
        }
    });
}

$results = awaitAll($coroutines);

$end_memory = memory_get_usage();
echo "Ending memory usage: " . number_format($end_memory) . " bytes\n";
echo "Memory difference: " . number_format($end_memory - $start_memory) . " bytes\n";
echo "Successfully completed $lookup_count concurrent DNS lookups\n";
echo "Results received: " . count($results) . "\n";

?>
--EXPECTF--
DNS memory stress test
Starting memory usage: %s bytes
Ending memory usage: %s bytes
Memory difference: %s bytes
Successfully completed 50 concurrent DNS lookups
Results received: 50