--TEST--
stream_select with async coroutine switching
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$sockets = create_socket_pair();
list($sock1, $sock2) = $sockets;

// Coroutine using stream_select
$selector = spawn(function() use ($sock1, $sock2) {
    echo "Selector: setting up stream_select\n";
    
    $read = [$sock2];
    $write = null;
    $except = null;
    
    echo "Selector: calling stream_select\n";
    $result = stream_select($read, $write, $except, 1);
    
    if ($result > 0) {
        echo "Selector: data available\n";
        $data = fread($sock2, 1024);
        echo "Selector: read '$data'\n";
    } else {
        echo "Selector: timeout or no data\n";
    }
    
    fclose($sock2);
});

// Writer coroutine
$writer = spawn(function() use ($sock1) {
    echo "Writer: writing data\n";
    fwrite($sock1, "test data");
    echo "Writer: data written\n";
    fclose($sock1);
});

// Worker to show parallel execution
$worker = spawn(function() {
    echo "Worker: working during stream_select\n";
    echo "Worker: finished\n";
});

await_all([$selector, $writer, $worker]);
echo "End\n";

?>
--EXPECT--
Start
Selector: setting up stream_select
Selector: calling stream_select
Writer: writing data
Writer: data written
Worker: working during stream_select
Worker: finished
Selector: data available
Selector: read 'test data'
End