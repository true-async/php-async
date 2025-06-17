--TEST--
DNS error handling in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    $is_windows = (DIRECTORY_SEPARATOR === '\\');
    
    // Test invalid hostname - should return original string
    $result = gethostbyname('');
    echo "Empty hostname: '$result'\n";
    
    // Test very long hostname (CVE-2015-0235 protection)
    // Different limits on different platforms
    $max_length = $is_windows ? 255 : 253; // Windows tends to be more permissive
    $long_hostname = str_repeat('a', $max_length + 50) . '.example.com';
    
    try {
        $result = gethostbyname($long_hostname);
        if ($result === $long_hostname) {
            echo "Long hostname returned unchanged (failed resolution)\n";
        } else {
            echo "Long hostname resolved: " . substr($result, 0, 50) . "...\n";
        }
    } catch (Throwable $e) {
        echo "Long hostname error: " . $e->getMessage() . "\n";
    }
    
    // Test gethostbynamel with empty hostname
    try {
        $result = gethostbynamel('');
        if ($result === false) {
            echo "Empty hostname in gethostbynamel returned false\n";
        } else {
            echo "Empty hostname in gethostbynamel: unexpected result\n";
            var_dump($result);
        }
    } catch (Throwable $e) {
        echo "Empty hostname error in gethostbynamel: " . $e->getMessage() . "\n";
    }
    
    // Test invalid IP address for gethostbyaddr
    $result = gethostbyaddr('999.999.999.999');
    var_dump($result);
    
    $result = gethostbyaddr('not.an.ip.address');  
    var_dump($result);
    
    // Test platform-specific invalid formats
    if ($is_windows) {
        // Windows might handle some formats differently
        $result = gethostbyaddr('256.1.1.1'); // Out of range octet
        var_dump($result);
    } else {
        // Unix-specific invalid formats
        $result = gethostbyaddr('300.300.300.300');
        var_dump($result);
    }
});

await($coroutine);

?>
--EXPECTF--
Empty hostname: ''

%a
bool(false)
%a
bool(false)
%a
bool(false)