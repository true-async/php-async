--TEST--
Poll2 async: Basic socket stream operations in coroutine context
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Before spawn\n";

$coroutine = spawn(function() {
    echo "Creating socket pair\n";
    
    $sockets = create_socket_pair();
    if (!$sockets) {
        echo "Failed to create socket pair\n";
        return;
    }
    
    list($sock1, $sock2) = $sockets;
    
    echo "Writing to socket\n";
    $written = fwrite($sock1, "test message");
    echo "Written: $written bytes\n";
    
    echo "Reading from socket\n";
    $data = fread($sock2, 1024);
    echo "Read: '$data'\n";
    
    fclose($sock1);
    fclose($sock2);
    
    return "socket test completed";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "After spawn\n";

?>
--EXPECT--
Before spawn
Creating socket pair
Writing to socket
Written: 12 bytes
Reading from socket
Read: 'test message'
Result: socket test completed
After spawn