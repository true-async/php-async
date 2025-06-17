--TEST--
Multiple socket operations with coroutine switching
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\awaitAll;

echo "Start\n";

// Create multiple socket pairs
$sockets1 = create_socket_pair();
$sockets2 = create_socket_pair();

list($sock1a, $sock1b) = $sockets1;
list($sock2a, $sock2b) = $sockets2;

// First socket pair operations
$worker1 = spawn(function() use ($sock1a, $sock1b) {
    echo "Worker1: writing to socket1\n";
    fwrite($sock1a, "message1");
    echo "Worker1: reading from socket1\n";
    $data = fread($sock1b, 1024);
    echo "Worker1: got '$data'\n";
    
    fclose($sock1a);
    fclose($sock1b);
});

// Second socket pair operations
$worker2 = spawn(function() use ($sock2a, $sock2b) {
    echo "Worker2: writing to socket2\n";
    fwrite($sock2a, "message2");
    echo "Worker2: reading from socket2\n";
    $data = fread($sock2b, 1024);
    echo "Worker2: got '$data'\n";
    
    fclose($sock2a);
    fclose($sock2b);
});

// Third coroutine just working
$worker3 = spawn(function() {
    echo "Worker3: doing other work\n";
});

awaitAll([$worker1, $worker2, $worker3]);
echo "End\n";

?>
--EXPECT--
Start
Worker1: writing to socket1
Worker1: reading from socket1
Worker2: writing to socket2
Worker2: reading from socket2
Worker3: doing other work
Worker1: got 'message1'
Worker2: got 'message2'
End