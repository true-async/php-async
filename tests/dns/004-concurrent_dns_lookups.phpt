--TEST--
Concurrent DNS lookups in async context
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "Starting concurrent DNS lookups\n";

$coroutines = [];

// Test concurrent gethostbyname
for ($i = 0; $i < 5; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        $hostname = $i % 2 == 0 ? 'localhost' : '127.0.0.1';
        $ip = gethostbyname($hostname);
        echo "Coroutine $i: $hostname -> $ip\n";
        return $ip;
    });
}

// Test concurrent gethostbyaddr
for ($i = 5; $i < 8; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        $hostname = gethostbyaddr('127.0.0.1');
        echo "Coroutine $i: 127.0.0.1 -> $hostname\n";
        return $hostname;
    });
}

[$results, $exceptions] = awaitAll($coroutines);
echo "All DNS lookups completed\n";
echo "Total results: " . count($results) . "\n";

?>
--EXPECTF--
Starting concurrent DNS lookups
Coroutine %d: %s -> %s
Coroutine %d: %s -> %s
Coroutine %d: %s -> %s
Coroutine %d: %s -> %s
Coroutine %d: %s -> %s
Coroutine %d: 127.0.0.1 -> %s
Coroutine %d: 127.0.0.1 -> %s
Coroutine %d: 127.0.0.1 -> %s
All DNS lookups completed
Total results: 8