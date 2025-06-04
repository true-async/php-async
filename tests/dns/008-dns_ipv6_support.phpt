--TEST--
DNS IPv6 support in async context
--SKIPIF--
<?php
if (!defined('AF_INET6')) {
    die('skip IPv6 not supported on this platform');
}
// Check if we have working IPv6 resolution
$test_ipv6 = @gethostbyaddr('::1');
if ($test_ipv6 === false) {
    die('skip IPv6 resolution not working');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    echo "Testing IPv6 DNS support\n";
    
    // Test IPv6 localhost
    $hostname = gethostbyaddr('::1');
    if ($hostname !== false) {
        echo "IPv6 localhost (::1) resolved to: $hostname\n";
    } else {
        echo "IPv6 localhost (::1) resolution failed\n";
    }
    
    // Test IPv6 loopback variations  
    $addresses = ['0:0:0:0:0:0:0:1', '::1'];
    foreach ($addresses as $addr) {
        $hostname = gethostbyaddr($addr);
        if ($hostname !== false) {
            echo "IPv6 address $addr resolved to: $hostname\n";
        } else {
            echo "IPv6 address $addr resolution failed\n";
        }
    }
    
    // Test invalid IPv6
    $hostname = gethostbyaddr('gggg::1');
    var_dump($hostname);
});

await($coroutine);

?>
--EXPECTF--
Testing IPv6 DNS support
%s
%s
bool(false)