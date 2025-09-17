--TEST--
Socket limits exhaustion handling in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start socket limits exhaustion test\n";

$test = spawn(function() {
    $sockets = [];
    $max_attempts = 1000; // Try to create many sockets
    $successful = 0;
    $failed = 0;

    echo "Creating multiple sockets to test limits\n";

    for ($i = 0; $i < $max_attempts; $i++) {
        try {
            $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
            if ($socket) {
                $sockets[] = $socket;
                $successful++;
            } else {
                $failed++;
                if ($errno == 24) { // EMFILE - Too many open files
                    echo "Reached file descriptor limit (EMFILE) at socket $i\n";
                    break;
                } elseif ($errno == 23) { // ENFILE - File table overflow
                    echo "Reached system file table limit (ENFILE) at socket $i\n";
                    break;
                }
            }
        } catch (Exception $e) {
            echo "Exception creating socket $i: " . $e->getMessage() . "\n";
            $failed++;
            break;
        }

        // Check if we're approaching reasonable limits
        if ($successful > 500) {
            echo "Created $successful sockets successfully, stopping test\n";
            break;
        }
    }

    echo "Created $successful sockets, failed $failed\n";

    // Test that we can still create sockets after cleanup
    foreach ($sockets as $socket) {
        fclose($socket);
    }

    echo "Cleaned up all sockets\n";

    // Verify we can create new sockets after cleanup
    $test_socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if ($test_socket) {
        echo "Successfully created socket after cleanup\n";
        fclose($test_socket);
    } else {
        echo "Failed to create socket after cleanup: $errstr\n";
    }

    return $successful;
});

$result = await($test);
echo "Test completed with $result sockets created\n";

?>
--EXPECTF--
Start socket limits exhaustion test
Creating multiple sockets to test limits
%a
Created %d sockets, failed %d
Cleaned up all sockets
Successfully created socket after cleanup
Test completed with %d sockets created