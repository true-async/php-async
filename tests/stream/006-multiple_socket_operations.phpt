--TEST--
Multiple socket operations with coroutine switching
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\awaitAll;

echo "Start\n";

// Array to collect output from spawn functions
$output = [];

// Create multiple socket pairs
$sockets1 = create_socket_pair();
$sockets2 = create_socket_pair();

list($sock1a, $sock1b) = $sockets1;
list($sock2a, $sock2b) = $sockets2;

// First socket pair operations
$worker1 = spawn(function() use ($sock1a, $sock1b, &$output) {
    $output[] = "Worker1: writing to socket1";
    fwrite($sock1a, "message1");
    $output[] = "Worker1: reading from socket1";
    $data = fread($sock1b, 1024);
    $output[] = "Worker1: got '$data'";
    
    fclose($sock1a);
    fclose($sock1b);
});

// Second socket pair operations
$worker2 = spawn(function() use ($sock2a, $sock2b, &$output) {
    $output[] = "Worker2: writing to socket2";
    fwrite($sock2a, "message2");
    $output[] = "Worker2: reading from socket2";
    $data = fread($sock2b, 1024);
    $output[] = "Worker2: got '$data'";
    
    fclose($sock2a);
    fclose($sock2b);
});

// Third coroutine just working
$worker3 = spawn(function() use (&$output) {
    $output[] = "Worker3: doing other work";
});

awaitAll([$worker1, $worker2, $worker3]);

// Sort and output results
sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

echo "End\n";

?>
--EXPECT--
Start
Worker1: got 'message1'
Worker1: reading from socket1
Worker1: writing to socket1
Worker2: got 'message2'
Worker2: reading from socket2
Worker2: writing to socket2
Worker3: doing other work
End