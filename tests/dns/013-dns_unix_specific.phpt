--TEST--
DNS basic hostname resolution in async context
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR === '\\') {
    die('skip Unix-only test');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    echo "Testing basic DNS hostname resolution\n";
    
    // Test basic localhost resolution
    $ip = gethostbyname('localhost');
    echo "localhost -> $ip\n";
    
    // Test case sensitivity
    $variations = ['localhost', 'LOCALHOST', 'LocalHost'];
    $case_sensitive = false;
    
    foreach ($variations as $var) {
        $ip = gethostbyname($var);
        if ($ip !== $var && $ip === '127.0.0.1') {
            continue;
        } elseif ($ip === $var) {
            $case_sensitive = true;
            break;
        }
    }
    
    echo "Case sensitivity detected: " . ($case_sensitive ? 'yes' : 'no') . "\n";
});

await($coroutine);

?>
--EXPECTF--
Testing basic DNS hostname resolution
localhost -> 127.0.0.1
Case sensitivity detected: %s