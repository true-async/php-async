--TEST--
Concurrent DNS lookups in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Starting concurrent DNS lookups\n";

$coroutines = [];

// Test concurrent gethostbyname
for ($i = 0; $i < 5; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        $hostname = $i % 2 == 0 ? 'localhost' : '127.0.0.1';
        $ip = gethostbyname($hostname);
        return ['coroutine' => $i, 'input' => $hostname, 'output' => $ip];
    });
}

// Test concurrent gethostbyaddr
for ($i = 5; $i < 8; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        $hostname = gethostbyaddr('127.0.0.1');
        return ['coroutine' => $i, 'input' => '127.0.0.1', 'output' => $hostname];
    });
}

[$results, $exceptions] = await_all($coroutines);

// Print results in deterministic order
foreach ($results as $result) {
    echo "Coroutine {$result['coroutine']}: {$result['input']} -> {$result['output']}\n";
}

echo "All DNS lookups completed\n";
echo "Total results: " . count($results) . "\n";

?>
--EXPECTF--
Starting concurrent DNS lookups
Coroutine 0: localhost -> %s
Coroutine 1: 127.0.0.1 -> %s
Coroutine 2: localhost -> %s
Coroutine 3: 127.0.0.1 -> %s
Coroutine 4: localhost -> %s
Coroutine 5: 127.0.0.1 -> %s
Coroutine 6: 127.0.0.1 -> %s
Coroutine 7: 127.0.0.1 -> %s
All DNS lookups completed
Total results: 8