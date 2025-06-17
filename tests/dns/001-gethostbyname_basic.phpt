--TEST--
DNS gethostbyname() basic functionality in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    $ip = gethostbyname('localhost');
    echo "localhost resolved to: $ip\n";
    
    $ip = gethostbyname('127.0.0.1');
    echo "127.0.0.1 resolved to: $ip\n";
    
    // Test invalid hostname fallback
    $ip = gethostbyname('invalid.nonexistent.domain.example');
    echo "invalid hostname returned: $ip\n";
});

await($coroutine);

?>
--EXPECTF--
localhost resolved to: 127.0.0.1
127.0.0.1 resolved to: 127.0.0.1
invalid hostname returned: invalid.nonexistent.domain.example