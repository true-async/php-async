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
use function Async\await;
use function Async\delay;

// Configuration
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 8080);
$keepaliveTimeout = 30; // seconds

$socketCoroutines = 0;
$socketCoroutinesRun = 0;
$socketCoroutinesFinished = 0;
$requestCount = 0;
$requestHandled = 0;

echo "=== Async HTTP Server with Keep-Alive ===\n";
echo "Starting server on http://$host:$port\n";
echo "Keep-Alive timeout: {$keepaliveTimeout}s\n";
echo "Press Ctrl+C to stop\n\n";


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
function parseHttpRequest($request)
{
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
 * Process HTTP request and send response
 */
function processHttpRequest($client, $rawRequest)
{
    global $cachedResponses;

    $parsedRequest = parseHttpRequest($rawRequest);
    $uri = $parsedRequest['uri'];
    $shouldKeepAlive = !$parsedRequest['connection_close'];

    // Use cached responses for static content
    if (isset($cachedResponses[$uri])) {
        $responseBody = $cachedResponses[$uri];
        $statusCode = 200;
    } else {
        // 404 response
        $responseBody = json_encode(['error' => 'Not Found', 'uri' => $uri], JSON_UNESCAPED_SLASHES);
        $statusCode = 404;
    }

    // Build and send response directly
    $contentLength = strlen($responseBody);
    $statusText = $statusCode === 200 ? 'OK' : 'Not Found';

    if ($shouldKeepAlive) {
        $response = 'HTTP/1.1 ' . $statusCode . ' ' . $statusText . "\r\n" .
                   'Content-Type: application/json' . "\r\n" .
                   'Content-Length: ' . $contentLength . "\r\n" .
                   'Server: AsyncKeepAlive/1.0' . "\r\n" .
                   'Connection: keep-alive' . "\r\n" .
                   'Keep-Alive: timeout=30, max=1000' . "\r\n\r\n" . $responseBody;
    } else {
        $response = 'HTTP/1.1 ' . $statusCode . ' ' . $statusText . "\r\n" .
                   'Content-Type: application/json' . "\r\n" .
                   'Content-Length: ' . $contentLength . "\r\n" .
                   'Server: AsyncKeepAlive/1.0' . "\r\n" .
                   'Connection: close' . "\r\n\r\n" . $responseBody;
    }

    $written = fwrite($client, $response);

    if ($written === false) {
        return false; // Write failed
    }

    return $shouldKeepAlive;
}

/**
 * Handle socket connection with keep-alive support
 * Each socket gets its own coroutine that lives for the entire connection
 */
function handleSocket($client)
{
    global $socketCoroutinesRun, $socketCoroutinesFinished;
    global $requestCount, $requestHandled;

    $socketCoroutinesRun++;

    try {
        while (true) {
            $request = '';
            $totalBytes = 0;

            // Read HTTP request with byte counting
            while (true) {
                $chunk = fread($client, 1024);

                if ($chunk === false || $chunk === '') {
                    // Connection closed by client or read error
                    return;
                }

                $request .= $chunk;
                $totalBytes += strlen($chunk);

                // Check for request size limit
                if ($totalBytes > 8192) {
                    // Request too large, close connection immediately
                    fclose($client);
                    $requestCount++;
                    $requestHandled++;
                    return;
                }

                // Check if we have complete HTTP request (ends with \r\n\r\n)
                if (strpos($request, "\r\n\r\n") !== false) {
                    break;
                }
            }

            if (empty(trim($request))) {
                // Empty request, skip to next iteration
                continue;
            }

            $requestCount++;

            // Process request and send response
            $shouldKeepAlive = processHttpRequest($client, $request);

            $requestHandled++;

            if ($shouldKeepAlive === false) {
                // Write failed or connection should be closed
                return;
            }

            // Continue to next request in keep-alive connection
        }

    } finally {
        $socketCoroutinesFinished++;
        // Always clean up the socket
        if (is_resource($client)) {
            fclose($client);
        }
    }
}

/**
 * HTTP Server with Keep-Alive support
 * Simple coroutine-based implementation without stream_select
 */
function startHttpServer($host, $port) {
    return spawn(function() use ($host, $port) {

        global $socketCoroutines;

        // Create server socket
        $server = stream_socket_server("tcp://$host:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!$server) {
            throw new Exception("Could not create server: $errstr ($errno)");
        }

	stream_context_set_option($server, 'socket', 'tcp_nodelay', true);

        echo "Server listening on $host:$port\n";
        echo "Try: curl http://$host:$port/\n";
        echo "Benchmark: wrk -t12 -c400 -d30s http://$host:$port/\n\n";

        // Simple accept loop - much cleaner!
        while (true) {
            // Accept new connections
            $client = stream_socket_accept($server, 0);
            if ($client) {
                $socketCoroutines++;
                // Spawn a coroutine to handle this client's entire lifecycle
                spawn(handleSocket(...), $client);
            }
        }

        fclose($server);
    });
}

spawn(function() {

    global $socketCoroutines, $socketCoroutinesRun, $socketCoroutinesFinished, $requestCount, $requestHandled;

    while(true) {
        delay(2000);
        echo "Sockets: $socketCoroutines\n";
        echo "Coroutines: $socketCoroutinesRun\n";
        echo "Finished: $socketCoroutinesFinished\n";
        echo "Request: $requestCount\n";
        echo "Handled: $requestHandled\n\n";
    }
});

// Start server
try {
    $serverTask = startHttpServer($host, $port);
    await($serverTask);

} catch (Exception $e) {
    echo "Server error: " . $e->getMessage() . "\n";
} finally {
    echo "Sockets: $socketCoroutines\n";
    echo "Coroutines: $socketCoroutinesRun\n";
    echo "Finished: $socketCoroutinesFinished\n";
}
