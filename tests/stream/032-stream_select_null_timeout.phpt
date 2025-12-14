--TEST--
stream_select with null timeout (infinite wait with coroutine yield)
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\awaitAll;

echo "Testing stream_select with null timeout\n";

$sockets = create_socket_pair();
if (!$sockets) {
    echo "Failed to create socket pair\n";
    exit(1);
}

list($sock1, $sock2) = $sockets;

// Coroutine using stream_select with null timeout (infinite wait)
$selector = spawn(function() use ($sock2) {
    echo "Selector: calling stream_select with null timeout\n";

    $read = [$sock2];
    $write = null;
    $except = null;

    $start_time = microtime(true);
    // This should YIELD the coroutine and allow writer to run
    $result = stream_select($read, $write, $except, null);
    $elapsed = round((microtime(true) - $start_time) * 1000, 2);

    echo "Selector: stream_select returned after {$elapsed}ms\n";

    if ($result > 0) {
        echo "Selector: data available\n";
        $data = fread($sock2, 1024);
        echo "Selector: read '$data'\n";
    } else {
        echo "Selector: ERROR - should have received data!\n";
    }

    fclose($sock2);
    return "selector completed";
});

// Writer coroutine - writes after a small delay
$writer = spawn(function() use ($sock1) {
    // Small delay to ensure selector starts first
    \Async\delay(50);

    echo "Writer: writing data\n";
    fwrite($sock1, "test data from writer");
    fflush($sock1);
    echo "Writer: data written\n";

    fclose($sock1);
    return "writer completed";
});

// Worker to demonstrate parallel execution
$worker = spawn(function() {
    echo "Worker: executing during stream_select\n";
    \Async\delay(10);
    echo "Worker: finished\n";
    return "worker completed";
});

list($results, $errors) = awaitAll([$selector, $writer, $worker]);

echo "Results:\n";
foreach ($results as $i => $result) {
    echo "  Coroutine $i: $result\n";
}

echo "Test completed successfully\n";

?>
--EXPECTF--
Testing stream_select with null timeout
Selector: calling stream_select with null timeout
Worker: executing during stream_select
Worker: finished
Writer: writing data
Writer: data written
Selector: stream_select returned after %sms
Selector: data available
Selector: read 'test data from writer'
Results:
  Coroutine 0: selector completed
  Coroutine 1: writer completed
  Coroutine 2: worker completed
Test completed successfully
