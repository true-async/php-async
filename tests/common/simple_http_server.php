<?php
/**
 * Simple synchronous HTTP server for async tests
 * This server runs in a separate process to avoid interference with async tests
 * Usage: Start this server before running tests, or use start_test_server_process()
 */

function start_test_server_process($port = 8088) {
    $server_script = __FILE__;
    $cmd = PHP_BINARY . " $server_script $port > /dev/null 2>&1 & echo $!";
    $pid = exec($cmd);
    
    // Wait a bit for server to start
    usleep(100000); // 100ms
    
    return (int)$pid;
}

function stop_test_server_process($pid) {
    if (PHP_OS_FAMILY === 'Windows') {
        exec("taskkill /PID $pid /F 2>NUL");
    } else {
        exec("kill $pid 2>/dev/null");
    }
}

function run_test_server($port) {
    $server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
    if (!$server) {
        die("Failed to create server: $errstr ($errno)\n");
    }
    
    echo "Test HTTP server running on 127.0.0.1:$port\n";
    
    while (true) {
        $client = stream_socket_accept($server);
        if (!$client) continue;
        
        // Read the request
        $request = '';
        while (!feof($client)) {
            $line = fgets($client);
            $request .= $line;
            if (trim($line) === '') break; // End of headers
        }
        
        // Parse request line
        $lines = explode("\n", $request);
        $request_line = trim($lines[0]);
        preg_match('/^(\S+)\s+(\S+)\s+(\S+)$/', $request_line, $matches);
        
        if (count($matches) >= 3) {
            $method = $matches[1];
            $path = $matches[2];
            
            // Route requests
            $response = route_test_request($method, $path, $request);
        } else {
            $response = http_test_response(400, "Bad Request");
        }
        
        fwrite($client, $response);
        fclose($client);
    }
    
    fclose($server);
}

function route_test_request($method, $path, $full_request) {
    switch ($path) {
        case '/':
            return http_test_response(200, "Hello World");
            
        case '/json':
            return http_test_response(200, '{"message":"Hello JSON","status":"ok"}', 'application/json');
            
        case '/slow':
            // Simulate slow response (useful for timeout tests)
            usleep(500000); // 500ms
            return http_test_response(200, "Slow Response");
            
        case '/very-slow':
            // Very slow response (for timeout tests)
            sleep(2);
            return http_test_response(200, "Very Slow Response");
            
        case '/error':
            return http_test_response(500, "Internal Server Error");
            
        case '/not-found':
            return http_test_response(404, "Not Found");
            
        case '/large':
            // Large response for testing data transfer
            return http_test_response(200, str_repeat("ABCDEFGHIJ", 1000)); // 10KB
            
        case '/post':
            if ($method === 'POST') {
                // Extract body if present
                $body_start = strpos($full_request, "\r\n\r\n");
                $body = $body_start !== false ? substr($full_request, $body_start + 4) : '';
                return http_test_response(200, "POST received: " . strlen($body) . " bytes");
            } else {
                return http_test_response(405, "Method Not Allowed");
            }
            
        case '/headers':
            // Return request headers as response
            $headers = [];
            $lines = explode("\n", $full_request);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && strpos($line, ':') !== false) {
                    $headers[] = $line;
                }
            }
            return http_test_response(200, implode("\n", $headers));
            
        case '/echo':
            // Echo back the entire request
            return http_test_response(200, $full_request, 'text/plain');
            
        case '/redirect':
            // Simple redirect
            return "HTTP/1.1 302 Found\r\n" .
                   "Location: /\r\n" .
                   "Content-Length: 0\r\n" .
                   "Connection: close\r\n" .
                   "\r\n";
            
        default:
            return http_test_response(404, "Not Found");
    }
}

function http_test_response($code, $body, $content_type = 'text/plain') {
    $status_text = [
        200 => 'OK',
        302 => 'Found',
        400 => 'Bad Request', 
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error'
    ][$code] ?? 'Unknown';
    
    $length = strlen($body);
    
    return "HTTP/1.1 $code $status_text\r\n" .
           "Content-Type: $content_type\r\n" .
           "Content-Length: $length\r\n" .
           "Connection: close\r\n" .
           "Server: AsyncTestServer/1.0\r\n" .
           "\r\n" .
           $body;
}

// Helper functions for tests
function get_test_server_address($port = 8088) {
    return "127.0.0.1:$port";
}

function get_test_server_url($path = '/', $port = 8088) {
    return "http://127.0.0.1:$port$path";
}

// If called directly, run the server
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    $port = $argv[1] ?? 8088;
    run_test_server($port);
}