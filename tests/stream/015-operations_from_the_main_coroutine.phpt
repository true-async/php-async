--TEST--
Poll2 async: Asynchronous operations from the main coroutine
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing mixed sync/async operations\n";

// First do some synchronous socket operations
echo "Synchronous operations:\n";
$sync_sockets = create_socket_pair();
list($sync1, $sync2) = $sync_sockets;

fwrite($sync1, "sync message");
$sync_data = fread($sync2, 1024);
echo "Sync result: '$sync_data'\n";

fclose($sync1);
fclose($sync2);

// Now do async operations
echo "Asynchronous operations:\n";

$coroutine = spawn(function() {
    echo "Async: Creating socket pair\n";
    
    $sockets = create_socket_pair();
    if (!$sockets) {
        echo "Async: Failed to create socket pair\n";
        return;
    }
    
    list($sock1, $sock2) = $sockets;
    
    echo "Async: Writing message\n";
    fwrite($sock1, "async message");
    
    echo "Async: Reading message\n";
    $data = fread($sock2, 1024);
    echo "Async result: '$data'\n";
    
    fclose($sock1);
    fclose($sock2);
    
    return "async operations completed";
});

$result = await($coroutine);
echo "Final result: $result\n";

// More synchronous operations after async
echo "Post-async synchronous operations:\n";
$post_sockets = create_socket_pair();
list($post1, $post2) = $post_sockets;

fwrite($post1, "post-async message");
$post_data = fread($post2, 1024);
echo "Post-async result: '$post_data'\n";

fclose($post1);
fclose($post2);

?>
--EXPECT--
Testing mixed sync/async operations
Synchronous operations:
Sync result: 'sync message'
Asynchronous operations:
Async: Creating socket pair
Async: Writing message
Async: Reading message
Async result: 'async message'
Final result: async operations completed
Post-async synchronous operations:
Post-async result: 'post-async message'