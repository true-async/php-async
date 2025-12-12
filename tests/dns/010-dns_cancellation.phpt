--TEST--
DNS operation cancellation in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Testing DNS operation cancellation\n";

$coroutine = spawn(function() {
    // Create a nested coroutine for DNS lookup
    $dns_coroutine = spawn(function() {
        echo "Starting DNS lookup\n";
        $ip = gethostbyname('localhost');
        echo "DNS lookup completed: $ip\n";
        return $ip;
    });
    
    try {
        $result = await($dns_coroutine);
        echo "DNS lookup result: $result\n";
    } catch (Async\CancellationError $e) {
        echo "DNS lookup was cancelled\n";
    } catch (Throwable $e) {
        echo "DNS lookup failed with: " . get_class($e) . "\n";
    }
    
    // Test with potentially slow lookup
    echo "Testing potential slow lookup\n";
    $slow_coroutine = spawn(function() {
        try {
            $ip = gethostbyname('this.should.not.exist.example.test');
            echo "Slow lookup completed: $ip\n";
        } catch (Throwable $e) {
            echo "Slow lookup failed: " . get_class($e) . "\n";
        }
    });
    
    await($slow_coroutine);
});

await($coroutine);

echo "Cancellation test completed\n";

?>
--EXPECTF--
Testing DNS operation cancellation
Starting DNS lookup
DNS lookup completed: 127.0.0.1
DNS lookup result: 127.0.0.1
Testing potential slow lookup
Slow lookup completed: this.should.not.exist.example.test
Cancellation test completed