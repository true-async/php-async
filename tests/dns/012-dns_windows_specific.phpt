--TEST--
DNS Windows-specific functionality in async context
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR !== '\\') {
    die('skip Windows-only test');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    echo "Testing Windows-specific DNS behavior\n";
    
    // Test Windows-specific hostnames
    $hostnames = ['localhost', '127.0.0.1', 'LOCALHOST'];
    
    foreach ($hostnames as $hostname) {
        $ip = gethostbyname($hostname);
        echo "$hostname -> $ip\n";
    }
    
    // Test Windows NetBIOS names (if available)
    $computer_name = getenv('COMPUTERNAME');
    if ($computer_name) {
        echo "Computer name: $computer_name\n";
        $ip = gethostbyname($computer_name);
        echo "$computer_name -> $ip\n";
    }
    
    // Test some Windows-specific addresses
    $addresses = ['127.0.0.1', '::1'];
    foreach ($addresses as $addr) {
        $hostname = gethostbyaddr($addr);
        if ($hostname !== false) {
            echo "$addr -> $hostname\n";
        } else {
            echo "$addr -> resolution failed\n";
        }
    }
    
    // Test case sensitivity (Windows should be case-insensitive)
    $variations = ['localhost', 'LOCALHOST', 'LocalHost'];
    $results = [];
    foreach ($variations as $var) {
        $results[$var] = gethostbyname($var);
    }
    
    // Check if all variations resolve to the same IP
    $unique_ips = array_unique($results);
    echo "Case sensitivity test: " . (count($unique_ips) === 1 ? "passed" : "failed") . "\n";
});

await($coroutine);

?>
--EXPECTF--
Testing Windows-specific DNS behavior
localhost -> 127.0.0.1
127.0.0.1 -> 127.0.0.1
LOCALHOST -> 127.0.0.1
%a
Case sensitivity test: passed