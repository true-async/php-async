--TEST--
DNS gethostbynamel() basic functionality in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$order = [];

$c1 = spawn(function() use (&$order) {
    $order[] = 'dns_start';
    $ips = gethostbynamel('localhost');
    $order[] = 'dns_end';

    echo "localhost resolved to " . count($ips) . " addresses:\n";
    foreach ($ips as $ip) {
        echo "  $ip\n";
    }

    // Test invalid hostname
    $ips = gethostbynamel('invalid.nonexistent.domain.example');
    var_dump($ips);
});

$c2 = spawn(function() use (&$order) {
    $order[] = 'other_task';
    echo "Other task executed\n";
});

await($c1);
await($c2);

// Verify context switching happened
if (in_array('other_task', $order)) {
    echo "Context switch: OK\n";
}

?>
--EXPECTF--
Other task executed
localhost resolved to %d addresses:
  127.0.0.1%A
bool(false)
Context switch: OK
