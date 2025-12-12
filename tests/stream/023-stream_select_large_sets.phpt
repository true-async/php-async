--TEST--
stream_select with large stream sets
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select with large stream sets\n";

$coroutine = spawn(function() {
    $socket_pairs = [];
    $pair_count = 10;
    
    // Create multiple socket pairs
    echo "Creating $pair_count socket pairs\n";
    for ($i = 0; $i < $pair_count; $i++) {
        $sockets = create_socket_pair();
        if ($sockets) {
            $socket_pairs[] = $sockets;
        }
    }
    
    echo "Created " . count($socket_pairs) . " socket pairs\n";
    
    // Write to some sockets
    $write_indices = [2, 5];
    foreach ($write_indices as $idx) {
        if (isset($socket_pairs[$idx])) {
            list($sock1, $sock2) = $socket_pairs[$idx];
            fwrite($sock1, "data-$idx");
        }
    }
    
    // Prepare arrays for select
    $read = [];
    foreach ($socket_pairs as $pair) {
        list($sock1, $sock2) = $pair;
        $read[] = $sock2;
    }
    
    $write = $except = null;
    
    echo "Select with " . count($read) . " streams\n";
    
    $start_time = microtime(true);
    $result = stream_select($read, $write, $except, 1);
    $end_time = microtime(true);
    
    $elapsed = round(($end_time - $start_time) * 1000, 2);
    echo "Result: $result in {$elapsed}ms\n";
    echo "Ready streams: " . count($read) . "\n";
    
    // Read available data
    $data_count = 0;
    foreach ($read as $socket) {
        $data = fread($socket, 1024);
        if (!empty($data)) {
            $data_count++;
        }
    }
    
    echo "Streams with data: $data_count\n";
    
    // Cleanup
    foreach ($socket_pairs as $pair) {
        list($sock1, $sock2) = $pair;
        fclose($sock1);
        fclose($sock2);
    }
    
    return "large sets test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECTF--
Testing stream_select with large stream sets
Creating 10 socket pairs
Created 10 socket pairs
Select with 10 streams
Result: %d in %sms
Ready streams: %d
Streams with data: %d
Result: large sets test completed