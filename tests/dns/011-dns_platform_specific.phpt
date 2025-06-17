--TEST--
DNS platform-specific behavior in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    echo "Testing platform-specific DNS behavior\n";
    
    // Check platform
    $is_windows = (DIRECTORY_SEPARATOR === '\\');
    echo "Platform: " . ($is_windows ? "Windows" : "Unix-like") . "\n";
    
    // Test localhost resolution (should work everywhere)
    $ip = gethostbyname('localhost');
    echo "localhost resolves to: $ip\n";
    
    // Test reverse lookup of localhost IP
    $hostname = gethostbyaddr($ip);
    echo "$ip resolves to: " . ($hostname ?: 'failed') . "\n";
    
    // Check available DNS functions
    echo "Functions available:\n";
    echo "  gethostbyname: " . (function_exists('gethostbyname') ? 'yes' : 'no') . "\n";
    echo "  gethostbyaddr: " . (function_exists('gethostbyaddr') ? 'yes' : 'no') . "\n";
    echo "  gethostbynamel: " . (function_exists('gethostbynamel') ? 'yes' : 'no') . "\n";
    echo "  dns_get_record: " . (function_exists('dns_get_record') ? 'yes' : 'no') . "\n";
    echo "  dns_check_record: " . (function_exists('dns_check_record') ? 'yes' : 'no') . "\n";
    
    // Check constants
    echo "Constants available:\n";
    echo "  AF_INET6: " . (defined('AF_INET6') ? 'yes' : 'no') . "\n";
    echo "  DNS_A: " . (defined('DNS_A') ? 'yes' : 'no') . "\n";
    
    // Test hostname length limits (Windows vs Unix may differ)
    $short_name = 'test.local';
    $medium_name = str_repeat('a', 50) . '.local';
    $long_name = str_repeat('b', 200) . '.local';
    
    echo "Testing hostname lengths:\n";
    echo "  Short ($short_name): " . gethostbyname($short_name) . "\n";
    echo "  Medium: " . gethostbyname($medium_name) . "\n";
    echo "  Long: " . gethostbyname($long_name) . "\n";
});

await($coroutine);

?>
--EXPECTF--
Testing platform-specific DNS behavior
Platform: %s
localhost resolves to: %s
%s resolves to: %s
Functions available:
  gethostbyname: yes
  gethostbyaddr: yes
  gethostbynamel: yes
  dns_get_record: %s
  dns_check_record: %s
Constants available:
  AF_INET6: %s
  DNS_A: %s
Testing hostname lengths:
  Short (test.local): test.local
  Medium: %s
  Long: %s