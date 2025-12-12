<?php
/**
 * HTTP Server using AmphpPHP Built-in Server for Performance Comparison
 * Uses AmphpPHP v3.x ready-made HTTP server implementation
 * 
 * Usage:
 *   composer install
 *   php http_server_amphp.php [host] [port]
 *   
 * Test with wrk:
 *   wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8080/
 */

require_once __DIR__ . '/vendor/autoload.php';

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Psr\Log\NullLogger;

// Increase memory limit
ini_set('memory_limit', '512M');

// Configuration
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 8080);

echo "=== AmphpPHP Built-in HTTP Server ===\n";
echo "Starting server on http://$host:$port\n";
echo "Press Ctrl+C to stop\n\n";

// Cached JSON responses for performance (same as async version)
$cachedResponses = [
    '/' => json_encode(['message' => 'Hello from AmphpPHP Server!', 'server' => 'amphp'], JSON_UNESCAPED_SLASHES),
    '/health' => json_encode(['status' => 'healthy', 'keepalive' => true], JSON_UNESCAPED_SLASHES),
    '/small' => json_encode(['ok' => true], JSON_UNESCAPED_SLASHES),
    '/json' => json_encode(['data' => range(1, 100)], JSON_UNESCAPED_SLASHES),
];

// Create request handler using AmphpPHP's ready-made components
$requestHandler = new ClosureRequestHandler(function (Request $request): Response {
    global $cachedResponses;
    
    $uri = $request->getUri()->getPath();
    
    // Use cached responses for static content
    if (isset($cachedResponses[$uri])) {
        return new Response(
            status: 200,
            headers: [
                'content-type' => 'application/json',
                'server' => 'AmphpBuiltIn/1.0'
            ],
            body: $cachedResponses[$uri]
        );
    }
    
    // Dynamic endpoints
    if ($uri === '/benchmark') {
        $responseBody = json_encode([
            'id' => uniqid(), 
            'time' => microtime(true)
        ], JSON_UNESCAPED_SLASHES);
        
        return new Response(
            status: 200,
            headers: [
                'content-type' => 'application/json',
                'server' => 'AmphpBuiltIn/1.0'
            ],
            body: $responseBody
        );
    }
    
    // 404 response
    $responseBody = json_encode([
        'error' => 'Not Found', 
        'uri' => $uri
    ], JSON_UNESCAPED_SLASHES);
    
    return new Response(
        status: 404,
        headers: [
            'content-type' => 'application/json',
            'server' => 'AmphpBuiltIn/1.0'
        ],
        body: $responseBody
    );
});

// Create and start HTTP server using AmphpPHP's production-ready server
try {
    $server = SocketHttpServer::createForDirectAccess(new NullLogger());
    
    $server->expose(new InternetAddress($host, $port));
    $server->start($requestHandler, new DefaultErrorHandler());
    
    echo "Server listening on $host:$port using AmphpPHP built-in components\n";
    echo "Try: curl http://$host:$port/\n";
    echo "Benchmark: wrk -t12 -c400 -d30s --http1.1 http://$host:$port/benchmark\n\n";
    
    // Keep server running
    Amp\trapSignal(SIGINT);
    $server->stop();
    
} catch (Exception $e) {
    echo "Server error: " . $e->getMessage() . "\n";
    exit(1);
}