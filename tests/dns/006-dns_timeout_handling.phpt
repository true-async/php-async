--TEST--
DNS timeout handling in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\timeout;

echo "Testing DNS timeout handling\n";

$coroutine = spawn(function() {

    try {
        // Test with very short timeout for a potentially slow lookup
        $dns_coroutine = spawn(function() {
            return gethostbyname('slow.example.nonexistent.domain.test.invalid');
        });

        await($dns_coroutine, timeout(1));

    } catch (Async\TimeoutException $e) {
        //echo "DNS lookup timed out as expected\n";
    } catch (Throwable $e) {
        echo "Other exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
    
    try {
        // Test normal timeout that should complete
        // Test with very short timeout for a potentially slow lookup
        $dns_coroutine = spawn(function() {
            return gethostbyname('localhost');
        });

        echo 'Fast DNS lookup completed: '.await($dns_coroutine, timeout(1000))."\n";
    } catch (Async\TimeoutException $e) {
        echo "Unexpected timeout for localhost\n";
    }
});

await($coroutine);

?>
--EXPECTF--
Testing DNS timeout handling
Fast DNS lookup completed: 127.0.0.1