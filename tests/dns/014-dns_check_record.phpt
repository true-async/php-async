--TEST--
DNS check record functionality in async context
--SKIPIF--
<?php
if (!function_exists('dns_check_record')) {
    die('skip dns_check_record not available');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    echo "Testing DNS check record\n";
    
    $has_a_record = dns_check_record('localhost', 'A');
    echo "localhost A record exists: " . ($has_a_record ? 'yes' : 'no') . "\n";
});

await($coroutine);

?>
--EXPECTF--
Testing DNS check record
localhost A record exists: %s