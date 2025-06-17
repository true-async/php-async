--TEST--
Poll2 async: Nested coroutines with polling operations
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing nested coroutines with polling\n";

$outer_coroutine = spawn(function() {
    echo "Outer: Starting\n";
    
    // Create a socket pair for outer coroutine
    $outer_sockets = create_socket_pair();
    list($outer1, $outer2) = $outer_sockets;

    echo "Outer: Created sockets, spawning inner coroutine\n";
    
    // Spawn inner coroutine
    $inner_coroutine = spawn(function() {
        echo "Inner: Starting\n";
        
        // Create socket pair for inner coroutine
        $inner_sockets = create_socket_pair();
        list($inner1, $inner2) = $inner_sockets;
        stream_set_blocking($inner1, false);
        stream_set_blocking($inner2, false);
        
        echo "Inner: Writing and reading\n";
        fwrite($inner1, "inner message");
        $inner_data = fread($inner2, 1024);
        echo "Inner: Read '$inner_data'\n";
        
        fclose($inner1);
        fclose($inner2);
        
        echo "Inner: Completed\n";
        return "inner result";
    });
    
    echo "Outer: Waiting for inner coroutine\n";
    $inner_result = await($inner_coroutine);
    echo "Outer: Inner returned: '$inner_result'\n";
    
    echo "Outer: Performing own socket operations\n";
    fwrite($outer1, "outer message");
    $outer_data = fread($outer2, 1024);
    echo "Outer: Read '$outer_data'\n";
    
    fclose($outer1);
    fclose($outer2);
    
    echo "Outer: Completed\n";
    return "outer result with inner: $inner_result";
});

$final_result = await($outer_coroutine);
echo "Final: $final_result\n";

?>
--EXPECT--
Testing nested coroutines with polling
Outer: Starting
Outer: Created sockets, spawning inner coroutine
Outer: Waiting for inner coroutine
Inner: Starting
Inner: Writing and reading
Inner: Read 'inner message'
Inner: Completed
Outer: Inner returned: 'inner result'
Outer: Performing own socket operations
Outer: Read 'outer message'
Outer: Completed
Final: outer result with inner: inner result