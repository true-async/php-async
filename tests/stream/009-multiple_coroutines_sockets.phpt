--TEST--
Poll2 async: Multiple coroutines with socket operations
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;
use function Async\awaitAll;

echo "Before spawn\n";

// Array to collect output from spawn functions
$output = [];

$coroutines = [];

// Create multiple coroutines that will perform socket operations
for ($i = 1; $i <= 3; $i++) {
    $coroutines[] = spawn(function() use ($i, &$output) {
        $output[] = "Coroutine $i: Creating socket pair";
        
        $sockets = create_socket_pair();
        if (!$sockets) {
            $output[] = "Coroutine $i: Failed to create socket pair";
            return "failed";
        }
        
        list($sock1, $sock2) = $sockets;
        
        // Write unique message
        $message = "message from coroutine $i";
        $output[] = "Coroutine $i: Writing '$message'";
        fwrite($sock1, $message);
        
        // Read the message back
        $data = fread($sock2, 1024);
        $output[] = "Coroutine $i: Read '$data'";
        
        fclose($sock1);
        fclose($sock2);
        
        return "coroutine $i completed";
    });
}

[$results, $exceptions] = awaitAll($coroutines);

// Sort and output results
sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

foreach ($results as $i => $result) {
    echo "Result " . ($i + 1) . ": $result\n";
}

echo "All coroutines completed\n";

?>
--EXPECT--
Before spawn
Coroutine 1: Creating socket pair
Coroutine 1: Read 'message from coroutine 1'
Coroutine 1: Writing 'message from coroutine 1'
Coroutine 2: Creating socket pair
Coroutine 2: Read 'message from coroutine 2'
Coroutine 2: Writing 'message from coroutine 2'
Coroutine 3: Creating socket pair
Coroutine 3: Read 'message from coroutine 3'
Coroutine 3: Writing 'message from coroutine 3'
Result 1: coroutine 1 completed
Result 2: coroutine 2 completed
Result 3: coroutine 3 completed
All coroutines completed