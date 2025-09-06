<?php
/**
 * HTTP Server with Keep-Alive Support
 * High-performance HTTP server implementation with connection pooling
 * 
 * Usage:
 *   php http_server_keepalive.php [host] [port]
 *   
 * Test with wrk:
 *   wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8080/
 */

// Increase memory limit
ini_set('memory_limit', '512M');

use function Async\spawn;
use function Async\awaitAll;

// Configuration
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 8080);
$keepaliveTimeout = 30; // seconds

echo "=== Async HTTP Server with Keep-Alive ===\n";
echo "Starting server on http://$host:$port\n";
echo "Keep-Alive timeout: {$keepaliveTimeout}s\n";
echo "Press Ctrl+C to stop\n\n";

// Global connection pool
$activeConnections = [];
$connectionId = 0;

// Cached JSON responses for performance
$cachedResponses = [
    '/' => json_encode(['message' => 'Hello from Async Keep-Alive Server!', 'server' => 'async-keepalive'], JSON_UNESCAPED_SLASHES),
    '/health' => json_encode(['status' => 'healthy', 'keepalive' => true], JSON_UNESCAPED_SLASHES),
    '/small' => json_encode(['ok' => true], JSON_UNESCAPED_SLASHES),
    '/json' => json_encode(['data' => range(1, 100)], JSON_UNESCAPED_SLASHES),
];

/**
 * Fast HTTP request parsing for benchmarks - only extract URI
 */
function parseHttpRequest($request) {
    // Fast path: find first space and second space to extract URI
    $firstSpace = strpos($request, ' ');
    if ($firstSpace === false) return '/';
    
    $secondSpace = strpos($request, ' ', $firstSpace + 1);
    if ($secondSpace === false) return '/';
    
    $uri = substr($request, $firstSpace + 1, $secondSpace - $firstSpace - 1);
    
    // Check for Connection: close header (simple search)
    $connectionClose = stripos($request, 'connection: close') !== false;
    
    return [
        'uri' => $uri,
        'connection_close' => $connectionClose
    ];
}

/**
 * Fast request processing with cached responses
 */
function processHttpRequest($uri) {
    global $cachedResponses;
    
    // Use cached responses for static content
    if (isset($cachedResponses[$uri])) {
        return [$cachedResponses[$uri], 200];
    }
    
    // Dynamic endpoints
    if ($uri === '/benchmark') {
        $responseBody = json_encode(['id' => uniqid(), 'time' => microtime(true)], JSON_UNESCAPED_SLASHES);
        return [$responseBody, 200];
    }
    
    // 404 response
    $responseBody = json_encode(['error' => 'Not Found', 'uri' => $uri], JSON_UNESCAPED_SLASHES);
    return [$responseBody, 404];
}

/**
 * Fast HTTP response building
 */
function buildHttpResponse($responseBody, $statusCode, $keepAlive = true) {
    $statusText = $statusCode === 200 ? 'OK' : 'Not Found';
    $contentLength = strlen($responseBody);
    
    // Build response using array for better performance
    $headers = [
        "HTTP/1.1 $statusCode $statusText",
        "Content-Type: application/json",
        "Content-Length: $contentLength",
        "Server: AsyncKeepAlive/1.0"
    ];
    
    if ($keepAlive) {
        $headers[] = "Connection: keep-alive";
        $headers[] = "Keep-Alive: timeout=30, max=1000";
    } else {
        $headers[] = "Connection: close";
    }
    
    return implode("\r\n", $headers) . "\r\n\r\n" . $responseBody;
}

/**
 * Handle single HTTP request
 */
function handleSingleRequest($client) {
    global $activeConnections;
    
    // Read HTTP request
    $request = fread($client, 8192);
    if ($request === false || empty(trim($request))) {
        // Connection closed - remove from active connections
        $key = array_search($client, $activeConnections);
        if ($key !== false) {
            unset($activeConnections[$key]);
        }
        fclose($client);
        return;
    }
    
    $parsedRequest = parseHttpRequest($request);
    $shouldKeepAlive = !$parsedRequest['connection_close'];
    
    // Process request
    [$responseBody, $statusCode] = processHttpRequest($parsedRequest['uri']);
    
    // Send response
    $response = buildHttpResponse($responseBody, $statusCode, $shouldKeepAlive);
    fwrite($client, $response);
    
    // Close connection if requested by client
    if (!$shouldKeepAlive) {
        $key = array_search($client, $activeConnections);
        if ($key !== false) {
            unset($activeConnections[$key]);
        }
        fclose($client);
    }
}

/**
 * HTTP Server with Keep-Alive support
 */
function startHttpServer($host, $port) {
    return spawn(function() use ($host, $port) {
        global $activeConnections;
        
        // Create server socket
        $server = stream_socket_server("tcp://$host:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!$server) {
            throw new Exception("Could not create server: $errstr ($errno)");
        }
        
        echo "Server listening on $host:$port\n";
        echo "Try: curl http://$host:$port/\n";
        echo "Benchmark: wrk -t12 -c400 -d30s --http1.1 http://$host:$port/benchmark\n\n";
        
        // Event-driven main loop
        while (true) {
            $readSockets = [$server] + $activeConnections;
            $writeSockets = [];
            $exceptSockets = [];
            
            $ready = stream_select($readSockets, $writeSockets, $exceptSockets, 1);
            
            if ($ready > 0) {
                foreach ($readSockets as $socket) {
                    if ($socket === $server) {
                        // New connection
                        $client = stream_socket_accept($server, 0);
                        if ($client) {
                            $activeConnections[] = $client;
                        }
                    } else {
                        // Data from existing client
                        spawn(handleSingleRequest(...), $socket);
                    }
                }
            }
        }
        
        fclose($server);
    });
}


// Start server
try {
    $serverTask = startHttpServer($host, $port);
    
    // Run until interrupted
    awaitAll([$serverTask]);
    
} catch (Exception $e) {
    echo "Server error: " . $e->getMessage() . "\n";
    exit(1);
}