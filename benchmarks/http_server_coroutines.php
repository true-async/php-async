<?php
/**
 * HTTP Server using Async Coroutines
 * Simple HTTP server implementation for performance testing with wrk
 * 
 * Usage:
 *   php http_server_coroutines.php [host] [port]
 *   
 * Test with wrk:
 *   wrk -t12 -c400 -d30s http://127.0.0.1:8080/
 */

// Increase memory limit
ini_set('memory_limit', '512M');

use function Async\spawn;
use function Async\awaitAll;

// Configuration
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 8080);

echo "=== Async Coroutines HTTP Server ===\n";
echo "Starting server on http://$host:$port\n";
echo "Press Ctrl+C to stop\n\n";

/**
 * Handle HTTP request in coroutine
 */
function handleHttpRequest($client, $request_id) {
    // Read request
    $request = fread($client, 4096);

    // Parse HTTP request line
    $lines = explode("\r\n", $request);
    $request_line = $lines[0] ?? '';
    $parts = explode(' ', $request_line);
    $method = $parts[0] ?? 'UNKNOWN';
    $uri = $parts[1] ?? '/';

    // Simple router
    $response_data = match($uri) {
        '/' => ['message' => 'Hello from Async HTTP Server!', 'server' => 'async-coroutines'],
        '/health' => ['status' => 'healthy', 'uptime' => time()],
        '/json' => ['data' => range(1, 100), 'timestamp' => microtime(true)],
        '/small' => ['ok' => true],
        default => ['error' => 'Not Found', 'uri' => $uri]
    };

    $status_code = ($uri === '/' || $uri === '/health' || $uri === '/json' || $uri === '/small') ? 200 : 404;
    $response_body = json_encode($response_data, JSON_UNESCAPED_SLASHES);

    // Build HTTP response
    $response = "HTTP/1.1 $status_code " . ($status_code === 200 ? 'OK' : 'Not Found') . "\r\n";
    $response .= "Content-Type: application/json\r\n";
    $response .= "Content-Length: " . strlen($response_body) . "\r\n";
    $response .= "Server: AsyncCoroutines/1.0\r\n";
    $response .= "Connection: close\r\n";
    $response .= "\r\n";
    $response .= $response_body;

    // Send response
    fwrite($client, $response);
    fclose($client);
}

/**
 * HTTP Server using coroutines
 */
function startHttpServer($host, $port) {
    return spawn(function() use ($host, $port) {
        // Create server socket
        $server = stream_socket_server("tcp://$host:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!$server) {
            throw new Exception("Could not create server: $errstr ($errno)");
        }
        
        echo "Server listening on $host:$port\n";
        echo "Try: curl http://$host:$port/\n";
        echo "Benchmark: wrk -t12 -c400 -d30s http://$host:$port/\n\n";
        
        $request_id = 0;
        $active_handlers = [];
        
        while (true) {
            // Accept new connections (this is async in async extension)
            $client = stream_socket_accept($server, 0);

            if ($client) {
                $request_id++;
                
                // Handle request in separate coroutine
                spawn(handleHttpRequest(...), $client, $request_id);
            }
        }
        
        fclose($server);
    });
}

// Start server
try {
    $server_task = startHttpServer($host, $port);
    
    // Run until interrupted
    awaitAll([$server_task]);
    
} catch (Exception $e) {
    echo "Server error: " . $e->getMessage() . "\n";
    exit(1);
}