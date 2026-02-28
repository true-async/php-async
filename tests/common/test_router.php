<?php
/**
 * Router for PHP built-in development server used in async tests
 * This file handles all HTTP requests and provides various test endpoints
 */

// Parse the request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

// Route requests
switch ($path) {
    case '/':
        header('Content-Type: text/html; charset=UTF-8');
        echo "Hello World";
        break;
        
    case '/json':
        header('Content-Type: application/json');
        echo json_encode([
            'message' => 'Hello JSON',
            'status' => 'ok'
        ]);
        break;
        
    case '/slow':
        // Simulate slow response (useful for timeout tests)
        header('Content-Type: text/html; charset=UTF-8');
        usleep(500000); // 500ms
        echo "Slow Response";
        break;
        
    case '/very-slow':
        // Very slow response (for timeout tests)
        header('Content-Type: text/html; charset=UTF-8');
        sleep(2);
        echo "Very Slow Response";
        break;
        
    case '/error':
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo "Internal Server Error";
        break;
        
    case '/not-found':
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo "Not Found";
        break;
        
    case '/large':
        // Large response for testing data transfer
        header('Content-Type: text/plain; charset=UTF-8');
        echo str_repeat("ABCDEFGHIJ", 1000); // 10KB
        break;
        
    case '/post':
        if ($method === 'POST') {
            $body = file_get_contents('php://input');
            header('Content-Type: application/json');
            echo json_encode([
                'message' => 'POST received',
                'body_length' => strlen($body),
                'body' => $body,
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
            ]);
        } else {
            http_response_code(405);
            header('Allow: POST');
            echo "Method Not Allowed";
        }
        break;
        
    case '/headers':
        // Return request headers as JSON
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $method,
            'headers' => getallheaders(),
            'server_vars' => [
                'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
                'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? '',
                'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? '',
                'CONTENT_LENGTH' => $_SERVER['CONTENT_LENGTH'] ?? ''
            ]
        ]);
        break;
        
    case '/echo':
        // Echo back request information
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $method,
            'uri' => $uri,
            'headers' => getallheaders(),
            'body' => file_get_contents('php://input'),
            'get' => $_GET,
            'post' => $_POST
        ]);
        break;
        
    case '/redirect':
        // Simple redirect
        http_response_code(302);
        header('Location: /');
        header('Content-Type: text/html; charset=UTF-8');
        echo "Redirecting...";
        break;
        
    case '/redirect-permanent':
        http_response_code(301);
        header('Location: /');
        header('Content-Type: text/html; charset=UTF-8');
        echo "Moved Permanently";
        break;
        
    case '/cookie':
        // Test cookie handling
        header('Content-Type: text/html; charset=UTF-8');
        setcookie('test_cookie', 'test_value', time() + 3600);
        echo "Cookie set";
        break;
        
    case '/get-cookie':
        // Return cookies
        header('Content-Type: application/json');
        echo json_encode(['cookies' => $_COOKIE]);
        break;
        
    case '/auth':
        // Basic auth test
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            header('WWW-Authenticate: Basic realm="Test"');
            http_response_code(401);
            header('Content-Type: text/html; charset=UTF-8');
            echo "Authentication required";
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'user' => $_SERVER['PHP_AUTH_USER'],
                'password' => $_SERVER['PHP_AUTH_PW'] ?? ''
            ]);
        }
        break;
        
    case '/timeout':
        // Configurable timeout based on query parameter
        header('Content-Type: text/html; charset=UTF-8');
        $timeout = (int)($_GET['seconds'] ?? 1);
        sleep($timeout);
        echo "Slept for {$timeout} seconds";
        break;
        
    case '/status':
        // Return specific status code
        $code = (int)($_GET['code'] ?? 200);
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        echo "Status: {$code}";
        break;
        
    case '/chunked':
        // Test chunked transfer encoding
        header('Content-Type: text/html; charset=UTF-8');
        header('Transfer-Encoding: chunked');
        echo "First chunk\n";
        flush();
        usleep(100000); // 100ms
        echo "Second chunk\n";
        flush();
        usleep(100000);
        echo "Final chunk";
        break;
        
    case '/content-type':
        // Test different content types
        $type = $_GET['type'] ?? 'text';
        switch ($type) {
            case 'json':
                header('Content-Type: application/json');
                echo '{"type":"json"}';
                break;
            case 'xml':
                header('Content-Type: application/xml');
                echo '<?xml version="1.0"?><root><type>xml</type></root>';
                break;
            case 'binary':
                header('Content-Type: application/octet-stream');
                echo pack('N*', 1, 2, 3, 4);
                break;
            default:
                header('Content-Type: text/plain; charset=UTF-8');
                echo "Plain text response";
        }
        break;
        
    case '/upload':
        if ($method === 'POST' && !empty($_FILES)) {
            header('Content-Type: text/plain');
            $file = $_FILES['file'] ?? null;
            if ($file) {
                echo "{$file['name']}|{$file['type']}|{$file['size']}";
            } else {
                echo "No file received";
            }
        } else {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Expected POST with file upload";
        }
        break;

    default:
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo "Endpoint not found: {$path}";
}