--TEST--
DNS timeout handling in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\timeout;

echo "Testing DNS timeout handling\n";

$coroutine = spawn(function() {
    // Platform-specific timeout values
    $is_windows = (DIRECTORY_SEPARATOR === '\\');
    $short_timeout = $is_windows ? 0.01 : 0.001; // Windows DNS may be slower
    $normal_timeout = $is_windows ? 10.0 : 5.0;
    
    try {
        // Test with very short timeout for a potentially slow lookup
        timeout($short_timeout, function() {
            $ip = gethostbyname('slow.example.nonexistent.domain.test.invalid');
            echo "DNS lookup completed: $ip\n";
        });
    } catch (Async\TimeoutException $e) {
        echo "DNS lookup timed out as expected\n";
    } catch (Throwable $e) {
        echo "Other exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
    
    try {
        // Test normal timeout that should complete
        timeout($normal_timeout, function() {
            $ip = gethostbyname('localhost');
            echo "Fast DNS lookup completed: $ip\n";
        });
    } catch (Async\TimeoutException $e) {
        echo "Unexpected timeout for localhost\n";
    }
});

await($coroutine);

?>
--EXPECTF--
Testing DNS timeout handling
%s
Fast DNS lookup completed: 127.0.0.1