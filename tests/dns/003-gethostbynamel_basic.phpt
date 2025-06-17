--TEST--
DNS gethostbynamel() basic functionality in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    // Test localhost
    $ips = gethostbynamel('localhost');
    echo "localhost resolved to " . count($ips) . " addresses:\n";
    foreach ($ips as $ip) {
        echo "  $ip\n";
    }
    
    // Test invalid hostname
    $ips = gethostbynamel('invalid.nonexistent.domain.example');
    var_dump($ips);
});

await($coroutine);

?>
--EXPECTF--
localhost resolved to %d addresses:
  127.0.0.1%A
bool(false)