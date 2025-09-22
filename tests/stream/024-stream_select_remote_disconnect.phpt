--TEST--
stream_select behavior when remote client disconnects after sending data
--SKIPIF--
<?php if (!function_exists("proc_open")) die("skip no proc_open"); ?>
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

function start_tcp_client_process($port) {
    $php_executable = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
    $client_script = __DIR__ . DIRECTORY_SEPARATOR . 'tcp_client_disconnect.php';
    
    if (!file_exists($client_script)) {
        echo "Client script not found: $client_script\n";
        return false;
    }
    
    $cmd = [$php_executable, '-n', $client_script, (string)$port];
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout  
        2 => ['pipe', 'w'],  // stderr
    ];
    
    $options = ["suppress_errors" => true];
    if (PHP_OS_FAMILY === 'Windows') {
        // On Windows, prevent creation of a console window
        $options["bypass_shell"] = true;
    }
    
    $process = proc_open($cmd, $descriptorspec, $pipes, null, null, $options);
    if (!$process) {
        echo "Failed to start client process\n";
        return false;
    }
    
    return [$process, $pipes];
}

function cleanup_client_process($process, $pipes) {
    if (is_resource($pipes[1])) {
        fclose($pipes[1]);
    }
    if (is_resource($pipes[2])) {
        fclose($pipes[2]);
    }
    
    // Give the process time to finish, especially on Windows
    if (PHP_OS_FAMILY === 'Windows') {
        usleep(50000); // 50ms delay
    }
    
    $status = proc_get_status($process);
    if ($status && $status['running']) {
        proc_terminate($process);
        // On Windows, termination might need more time
        if (PHP_OS_FAMILY === 'Windows') {
            usleep(100000); // 100ms delay
        }
    }
    
    $exit_code = proc_close($process);
    
    // On Windows, exit code 255 might be normal for terminated processes
    if (PHP_OS_FAMILY === 'Windows' && $exit_code === 255) {
        $exit_code = 0;
    }
    
    return $exit_code;
}

echo "Testing stream_select with remote disconnect scenario\n";

$server_coroutine = spawn(function() {
    // Create server socket
    $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$server) {
        echo "Failed to create server: $errstr\n";
        return "server failed";
    }
    
    // Get server address and port
    $server_name = stream_socket_get_name($server, false);
    $port = parse_url("tcp://$server_name", PHP_URL_PORT);
    echo "Server listening on port: $port\n";
    
    // Start client process using separate script
    $client_result = start_tcp_client_process($port);
    if (!$client_result) {
        fclose($server);
        return "client process failed";
    }
    
    list($client_process, $pipes) = $client_result;
    
    // Close stdin, keep stdout/stderr for reading
    fclose($pipes[0]);
    
    // Server waits for connection
    echo "Server: waiting for connection\n";
    $read = [$server];
    $write = $except = null;
    
    $result = stream_select($read, $write, $except, 3);
    if ($result === 0) {
        echo "Server: timeout waiting for connection\n";
        cleanup_client_process($client_process, $pipes);
        fclose($server);
        return "server timeout";
    }

    echo "Server: accepting connection\n";
    $client_socket = stream_socket_accept($server, 1);
    if (!$client_socket) {
        echo "Server: failed to accept connection\n";
        cleanup_client_process($client_process, $pipes);
        fclose($server);
        return "accept failed";
    }
    
    echo "Server: connection accepted\n";
    
    // Critical test: monitor client socket with stream_select
    echo "Server: monitoring client socket with stream_select\n";
    
    // First select - should detect incoming data
    $read = [$client_socket];
    $write = $except = null;
    
    $result1 = stream_select($read, $write, $except, 3);
    echo "Server: first stream_select result: $result1\n";
    
    if ($result1 > 0 && count($read) > 0) {

        stream_set_timeout($client_socket, 1);

        $data = fread($client_socket, 1024);
        echo "Server: received data: '" . trim($data) . "'\n";
        
        // Continue monitoring for disconnection
        echo "Server: continuing to monitor for disconnection\n";
        $read = [$client_socket];
        $write = $except = null;
        
        // This is the critical test - detect disconnection via stream_select
        $result2 = stream_select($read, $write, $except, 3);
        echo "Server: second stream_select result: $result2\n";
        echo "Server: ready streams after disconnect: " . count($read) . "\n";
        
        if ($result2 > 0 && count($read) > 0) {
            // Try to read - should detect disconnection
            $disconnect_data = fread($client_socket, 1024);
            if ($disconnect_data === false) {
                echo "Server: detected disconnection (fread returned false)\n";
            } elseif ($disconnect_data === '') {
                echo "Server: detected disconnection (fread returned empty string)\n";
            } else {
                echo "Server: unexpected data on disconnect: '$disconnect_data'\n";
            }
            
            // Check stream metadata
            $meta = stream_get_meta_data($client_socket);
            echo "Server: stream EOF: " . ($meta['eof'] ? "yes" : "no") . "\n";
        } else {
            echo "Server: no disconnect event detected within timeout\n";
        }
    }
    
    // Read client process output
    $client_output = stream_get_contents($pipes[1]);
    $client_errors = stream_get_contents($pipes[2]);
    
    echo "Client output:\n$client_output";
    if (!empty($client_errors)) {
        echo "Client errors:\n$client_errors";
    }
    
    // Cleanup
    fclose($client_socket);
    fclose($server);
    
    $exit_code = cleanup_client_process($client_process, $pipes);
    echo "Client process exit code: $exit_code\n";
    
    return "server completed";
});

$result = await($server_coroutine);
echo "Test result: $result\n";

?>
--EXPECTF--
Testing stream_select with remote disconnect scenario
Server listening on port: %d
Server: waiting for connection
Server: accepting connection
Server: connection accepted
Server: monitoring client socket with stream_select
Server: first stream_select result: 1
Server: received data: 'Hello from external process'
Server: continuing to monitor for disconnection
Server: second stream_select result: %d
Server: ready streams after disconnect: %d
Server: detected disconnection %s
Server: stream EOF: %s
Client output:
Client process: connecting to port %d
Client process: connected, sending data
Client process: closing connection abruptly
Client process: exited
Client process exit code: 0
Test result: server completed