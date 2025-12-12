--TEST--
Poll2 async: Nested coroutines with polling operations
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing nested coroutines with polling\n";

$outer_coroutine = spawn(function() use (&$output) {
    $output[] = "Outer: Starting";
    
    // Create a socket pair for outer coroutine
    $outer_sockets = create_socket_pair();
    list($outer1, $outer2) = $outer_sockets;

    $output[] = "Outer: Created sockets, spawning inner coroutine";
    
    // Spawn inner coroutine
    $inner_coroutine = spawn(function() use (&$output) {
        $output[] = "Inner: Starting";
        
        // Create socket pair for inner coroutine
        $inner_sockets = create_socket_pair();
        list($inner1, $inner2) = $inner_sockets;
        stream_set_blocking($inner1, false);
        stream_set_blocking($inner2, false);
        
        $output[] = "Inner: Writing and reading";
        fwrite($inner1, "inner message");
        $inner_data = fread($inner2, 1024);
        $output[] = "Inner: Read '$inner_data'";
        
        fclose($inner1);
        fclose($inner2);
        
        $output[] = "Inner: Completed";
        return "inner result";
    });
    
    $output[] = "Outer: Waiting for inner coroutine";
    $inner_result = await($inner_coroutine);
    $output[] = "Outer: Inner returned: '$inner_result'";
    
    $output[] = "Outer: Performing own socket operations";
    fwrite($outer1, "outer message");
    $outer_data = fread($outer2, 1024);
    $output[] = "Outer: Read '$outer_data'";
    
    fclose($outer1);
    fclose($outer2);
    
    $output[] = "Outer: Completed";
    return "outer result with inner: $inner_result";
});

$final_result = await($outer_coroutine);

// Sort and output results
sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

echo "Final: $final_result\n";

?>
--EXPECT--
Testing nested coroutines with polling
Inner: Completed
Inner: Read 'inner message'
Inner: Starting
Inner: Writing and reading
Outer: Completed
Outer: Created sockets, spawning inner coroutine
Outer: Inner returned: 'inner result'
Outer: Performing own socket operations
Outer: Read 'outer message'
Outer: Starting
Outer: Waiting for inner coroutine
Final: outer result with inner: inner result