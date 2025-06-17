--TEST--
Poll2 async: Concurrent socket operations
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\delay;
use function Async\awaitAll;
use function Async\suspend;

echo "Testing concurrent socket operations\n";

echo "Creating socket pair\n";

$sockets = create_socket_pair();
if (!$sockets) {
    echo "Failed to create socket pair\n";
    exit(1);
}

list($write_sock, $read_sock) = $sockets;

// Consumer coroutine
$consumer = spawn(function() use ($read_sock) {
    $messages = [];
    $attempts = 0;
    
    while ($attempts < 10) { // Max 10 attempts to prevent infinite loop
        $data = fread($read_sock, 1024);
        
        if ($data !== false && $data !== '') {
            echo "Consumer: Received data: '" . trim($data) . "'\n";
            $messages[] = trim($data);
            
            // Check if we got all messages
            if (count($messages) >= 3) {
                break;
            }
        }
        
        $attempts++;
    }
    
    fclose($read_sock);
    
    echo "Consumer: Total messages received: " . count($messages) . "\n";
});

$producer = spawn(function() use ($write_sock) {

    // Send multiple messages with delays
    for ($i = 1; $i <= 3; $i++) {
        echo "Producer: Sending message $i\n";
        fwrite($write_sock, "message$i\n");
        delay(50);
    }

    fclose($write_sock);
});

awaitAll([$producer, $consumer]);

?>
--EXPECT--
Testing concurrent socket operations
Creating socket pair
Producer: Sending message 1
Consumer: Received data: 'message1'
Producer: Sending message 2
Consumer: Received data: 'message2'
Producer: Sending message 3
Consumer: Received data: 'message3'
Consumer: Total messages received: 3