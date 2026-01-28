--TEST--
Poll2 async: Concurrent socket operations
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\delay;
use function Async\await_all;
use function Async\suspend;

echo "Testing concurrent socket operations\n";

echo "Creating socket pair\n";

// Array to collect output from spawn functions
$output = [];

$sockets = create_socket_pair();
if (!$sockets) {
    echo "Failed to create socket pair\n";
    exit(1);
}

list($write_sock, $read_sock) = $sockets;

// Consumer coroutine
$consumer = spawn(function() use ($read_sock, &$output) {
    $messages = [];
    $attempts = 0;
    
    while ($attempts < 10) { // Max 10 attempts to prevent infinite loop
        $data = fread($read_sock, 1024);
        
        if ($data !== false && $data !== '') {
            $output[] = "Consumer: Received data: '" . trim($data) . "'";
            $messages[] = trim($data);
            
            // Check if we got all messages
            if (count($messages) >= 3) {
                break;
            }
        }
        
        $attempts++;
    }
    
    fclose($read_sock);
    
    $output[] = "Consumer: Total messages received: " . count($messages);
});

$producer = spawn(function() use ($write_sock, &$output) {

    // Send multiple messages with delays
    for ($i = 1; $i <= 3; $i++) {
        $output[] = "Producer: Sending message $i";
        fwrite($write_sock, "message$i\n");
        delay(50);
    }

    fclose($write_sock);
});

await_all([$producer, $consumer]);

// Sort and output results
sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

?>
--EXPECT--
Testing concurrent socket operations
Creating socket pair
Consumer: Received data: 'message1'
Consumer: Received data: 'message2'
Consumer: Received data: 'message3'
Consumer: Total messages received: 3
Producer: Sending message 1
Producer: Sending message 2
Producer: Sending message 3