--TEST--
DNS IPv6 resolution in async context
--SKIPIF--
<?php
if (getenv("CI_NO_IPV6")) {
    die('skip IPv6 tests disabled in CI environment');
}
if (!defined('AF_INET6')) {
    die('skip IPv6 not supported');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    echo "Testing IPv6 DNS resolution\n";
    
    $ipv6_hostname = gethostbyaddr('::1');
    if ($ipv6_hostname !== false) {
        echo "::1 -> $ipv6_hostname\n";
    } else {
        echo "::1 -> resolution failed\n";
    }
});

await($coroutine);

?>
--EXPECTF--
Testing IPv6 DNS resolution
::1 -> %s