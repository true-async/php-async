--TEST--
DNS gethostbyaddr() basic functionality in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    // Test localhost
    $hostname = gethostbyaddr('127.0.0.1');
    echo "127.0.0.1 resolved to: $hostname\n";
    
    // Test loopback IPv6 (only if IPv6 is available)
    if (defined('AF_INET6')) {
        $hostname = gethostbyaddr('::1');
        if ($hostname !== false) {
            echo "::1 resolved to: $hostname\n";
        } else {
            echo "::1 resolution failed\n";
        }
    } else {
        echo "IPv6 not supported on this platform\n";
    }
    
    // Test invalid IP
    $hostname = gethostbyaddr('invalid.ip');
    var_dump($hostname);
});

await($coroutine);

?>
--EXPECTF--
127.0.0.1 resolved to: %s
%s
bool(false)