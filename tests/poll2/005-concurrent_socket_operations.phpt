--TEST--
Poll2 async: Concurrent socket operations with mixed blocking/non-blocking
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\awaitAll;

echo "Testing concurrent socket operations\n";

// Producer coroutine
$producer = spawn(function() {
    echo "Producer: Creating socket pair\n";
    
    $sockets = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!$sockets) {
        echo "Producer: Failed to create socket pair\n";
        return null;
    }
    
    list($write_sock, $read_sock) = $sockets;

    // Send multiple messages with delays
    for ($i = 1; $i <= 3; $i++) {
        echo "Producer: Sending message $i\n";
        fwrite($write_sock, "message$i\n");
        
        // Small delay to simulate work
        usleep(100000); // 0.1 second
    }
    
    fclose($write_sock);
    
    return $read_sock;
});

// Consumer coroutine 
$consumer = spawn(function() use ($producer) {
    echo "Consumer: Waiting for socket from producer\n";
    
    $read_sock = await($producer);
    if (!$read_sock) {
        echo "Consumer: No socket received\n";
        return;
    }
    
    echo "Consumer: Starting to read messages\n";
    
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
        usleep(50000); // 0.05 second between attempts
    }
    
    fclose($read_sock);
    
    echo "Consumer: Total messages received: " . count($messages) . "\n";
    return $messages;
});

$result = await($consumer);
echo "Final result: " . var_export($result, true) . "\n";

?>
--EXPECT--
Testing concurrent socket operations
Producer: Creating socket pair
Consumer: Waiting for socket from producer
Producer: Sending message 1
Producer: Sending message 2
Producer: Sending message 3
Consumer: Starting to read messages
Consumer: Received data: 'message1'
Consumer: Received data: 'message2'
Consumer: Received data: 'message3'
Consumer: Total messages received: 3
Final result: array (
  0 => 'message1',
  1 => 'message2',
  2 => 'message3',
)