--TEST--
DNS async vs sync behavior comparison
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Testing DNS async vs sync behavior\n";

// Test sync behavior (should be same as before)
echo "=== Sync DNS calls (outside async context) ===\n";
$sync_ip = gethostbyname('localhost');
echo "Sync gethostbyname(localhost): $sync_ip\n";

$sync_hostname = gethostbyaddr('127.0.0.1');
echo "Sync gethostbyaddr(127.0.0.1): $sync_hostname\n";

$sync_ips = gethostbynamel('localhost');
echo "Sync gethostbynamel(localhost): " . count($sync_ips) . " addresses\n";

// Test async behavior
echo "=== Async DNS calls (inside coroutine) ===\n";
$coroutine = spawn(function() {
    $async_ip = gethostbyname('localhost');
    echo "Async gethostbyname(localhost): $async_ip\n";
    
    $async_hostname = gethostbyaddr('127.0.0.1');
    echo "Async gethostbyaddr(127.0.0.1): $async_hostname\n";
    
    $async_ips = gethostbynamel('localhost');
    echo "Async gethostbynamel(localhost): " . count($async_ips) . " addresses\n";
});

await($coroutine);

echo "=== Comparison completed ===\n";

?>
--EXPECTF--
Testing DNS async vs sync behavior
=== Sync DNS calls (outside async context) ===
Sync gethostbyname(localhost): 127.0.0.1
Sync gethostbyaddr(127.0.0.1): %s
Sync gethostbynamel(localhost): %d addresses
=== Async DNS calls (inside coroutine) ===
Async gethostbyname(localhost): 127.0.0.1
Async gethostbyaddr(127.0.0.1): %s
Async gethostbynamel(localhost): %d addresses
=== Comparison completed ===