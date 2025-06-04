--TEST--
DNS Unix-specific functionality in async context
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR === '\\') {
    die('skip Unix-only test');
}
if (!function_exists('dns_get_record')) {
    die('skip dns_get_record not available');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    echo "Testing Unix-specific DNS behavior\n";
    
    // Test /etc/hosts entries (common on Unix)
    $hostnames = ['localhost', 'localhost.localdomain'];
    
    foreach ($hostnames as $hostname) {
        $ip = gethostbyname($hostname);
        echo "$hostname -> $ip\n";
    }
    
    // Test Unix hostname command integration
    $hostname_cmd = trim(`hostname 2>/dev/null`);
    if ($hostname_cmd && $hostname_cmd !== '') {
        echo "System hostname: $hostname_cmd\n";
        $ip = gethostbyname($hostname_cmd);
        echo "$hostname_cmd -> $ip\n";
    }
    
    // Test IPv6 loopback (more common on modern Unix)
    if (defined('AF_INET6')) {
        $ipv6_hostname = gethostbyaddr('::1');
        if ($ipv6_hostname !== false) {
            echo "::1 -> $ipv6_hostname\n";
        } else {
            echo "::1 -> resolution failed\n";
        }
    }
    
    // Test DNS record functions (usually available on Unix)
    if (function_exists('dns_check_record')) {
        $has_a_record = dns_check_record('localhost', 'A');
        echo "localhost A record exists: " . ($has_a_record ? 'yes' : 'no') . "\n";
    }
    
    // Test case sensitivity (Unix is typically case-sensitive for hostnames)
    $variations = ['localhost', 'LOCALHOST', 'LocalHost'];
    $case_sensitive = false;
    
    foreach ($variations as $var) {
        $ip = gethostbyname($var);
        if ($ip !== $var && $ip === '127.0.0.1') {
            // If it resolves to an IP instead of returning the hostname
            continue;
        } elseif ($ip === $var) {
            // If it returns the hostname unchanged (failed resolution)
            $case_sensitive = true;
            break;
        }
    }
    
    echo "Case sensitivity detected: " . ($case_sensitive ? 'yes' : 'no') . "\n";
});

await($coroutine);

?>
--EXPECTF--
Testing Unix-specific DNS behavior
localhost -> 127.0.0.1
%s -> %s
%s
%s
%s
Case sensitivity detected: %s