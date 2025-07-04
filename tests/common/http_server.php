<?php
/**
 * HTTP test server for async tests using PHP's built-in development server
 * Adapted from sapi/cli/tests/php_cli_server.inc
 */

class AsyncTestServerInfo {
    public function __construct(
        public string $docRoot,
        public $processHandle,
        public string $address,
        public int $port
    ) {}
}

function async_test_server_start(?string $router = null): AsyncTestServerInfo {
    $php_executable = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
    
    // Use the common test router by default
    if ($router === null) {
        $router = __DIR__ . '/test_router.php';
    }
    
    // Create dedicated doc root
    $doc_root = __DIR__ . DIRECTORY_SEPARATOR . 'server_' . uniqid();
    @mkdir($doc_root);

    $cmd = [$php_executable, '-t', $doc_root, '-n', '-S', 'localhost:0', $router];

    // Use unique temp file with PID and microtime for parallel workers
    $unique_id = getmypid() . '_' . microtime(true);
    $output_file = tempnam(sys_get_temp_dir(), "async_test_server_output_{$unique_id}_");
    $output_file_fd = fopen($output_file, 'ab');
    if ($output_file_fd === false) {
        die(sprintf("Failed opening output file %s\n", $output_file));
    }
    
    register_shutdown_function(function () use ($output_file) {
        @unlink($output_file);
    });

    $descriptorspec = array(
        0 => STDIN,
        1 => $output_file_fd,
        2 => $output_file_fd,
    );
    $handle = proc_open($cmd, $descriptorspec, $pipes, $doc_root, null, array("suppress_errors" => true));
    
    // Wait for the server to start
    $bound = null;
    for ($i = 0; $i < 120; $i++) { // Increased timeout for CI
        usleep(100000); // 100ms per try (was 50ms)
        
        // Force flush the output file
        if (is_resource($output_file_fd)) {
            fflush($output_file_fd);
        }
        
        $status = proc_get_status($handle);
        if (empty($status['running'])) {
            $output_content = file_get_contents($output_file);
            echo "Server failed to start\n";
            printf("Server output:\n%s\n", $output_content);
            printf("Output file: %s\n", $output_file);
            printf("Status: %s\n", print_r($status, true));
            fclose($output_file_fd);
            proc_terminate($handle);
            exit(1);
        }

        $output = file_get_contents($output_file);
        // Handle both formats: with and without timestamp prefix
        if (preg_match('@(?:\[[^\]]+\] )?PHP \S* Development Server \(https?://(.*?:\d+)\) started@', $output, $matches)) {
            $bound = $matches[1];
            break;
        }
        
        // Debug output every 20 iterations in CI
        if ($i > 0 && $i % 20 == 0 && getenv('CI')) {
            printf("Debug: iteration %d, output length: %d\n", $i, strlen($output));
        }
    }
    
    if ($bound === null) {
        $output_content = file_get_contents($output_file);
        $final_status = proc_get_status($handle);
        
        echo "Server did not output startup message\n";
        printf("Server output:\n%s\n", $output_content);
        printf("Output file: %s (size: %d)\n", $output_file, filesize($output_file));
        printf("Final status: %s\n", print_r($final_status, true));
        printf("Command: %s\n", implode(' ', $cmd));
        printf("Doc root: %s\n", $doc_root);
        printf("Router: %s\n", $router ?? 'default');
        
        fclose($output_file_fd);
        proc_terminate($handle);
        exit(1);
    }

    // Wait for a successful connection
    $error = "Unable to connect to server\n";
    for ($i = 0; $i < 60; $i++) {
        usleep(50000); // 50ms per try
        $status = proc_get_status($handle);
        $fp = @fsockopen("tcp://$bound");
        
        if (!($status && $status['running'])) {
            $error = sprintf("Server stopped\nServer output:\n%s\n", file_get_contents($output_file));
            break;
        }
        
        if ($fp) {
            fclose($fp);
            $error = '';
            break;
        }
    }

    if ($error) {
        echo $error;
        fclose($output_file_fd);
        proc_terminate($handle);
        exit(1);
    }

    register_shutdown_function(
        function($handle) use($doc_root, $output_file, $output_file_fd) {
            if (is_resource($handle) && get_resource_type($handle) === 'process') {
                $status = proc_get_status($handle);
                if ($status !== false && $status['running']) {
                    proc_terminate($handle);
                }
                proc_close($handle);
                
                if ($status !== false && $status['exitcode'] !== -1 && $status['exitcode'] !== 0
                        && !($status['exitcode'] === 255 && PHP_OS_FAMILY == 'Windows')) {
                    printf("Server exited with non-zero status: %d\n", $status['exitcode']);
                    printf("Server output:\n%s\n", file_get_contents($output_file));
                }
            }
            if (is_resource($output_file_fd)) {
                fclose($output_file_fd);
            }
            @unlink($output_file);
            remove_directory($doc_root);
        },
        $handle
    );

    $port = (int) substr($bound, strrpos($bound, ':') + 1);
    
    // Define global constants for backward compatibility
    if (!defined('ASYNC_TEST_SERVER_HOSTNAME')) {
        define('ASYNC_TEST_SERVER_HOSTNAME', 'localhost');
        define('ASYNC_TEST_SERVER_PORT', $port);
        define('ASYNC_TEST_SERVER_ADDRESS', "localhost:$port");
    }
    
    return new AsyncTestServerInfo($doc_root, $handle, $bound, $port);
}

function async_test_server_start_custom(string $router_file): AsyncTestServerInfo {
    return async_test_server_start($router_file);
}

function async_test_server_connect(AsyncTestServerInfo $server) {
    $timeout = 1.0;
    $fp = fsockopen('localhost', $server->port, $errno, $errstr, $timeout);
    if (!$fp) {
        die("connect failed: $errstr ($errno)");
    }
    return $fp;
}

function async_test_server_stop(AsyncTestServerInfo $server) {
    if ($server->processHandle && is_resource($server->processHandle) && get_resource_type($server->processHandle) === 'process') {
        $status = proc_get_status($server->processHandle);
        if ($status !== false && $status['running']) {
            proc_terminate($server->processHandle);
        }
        proc_close($server->processHandle);
        $server->processHandle = null;
    }
    
    // Always remove directory, regardless of process state
    remove_directory($server->docRoot);
}

function remove_directory($dir) {
    if (is_dir($dir) === false) {
        return;
    }
    
    // On Windows, give the process time to release the directory
    if (PHP_OS_FAMILY === 'Windows') {
        usleep(100000); // 100ms delay
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($dir);
}

function start_test_server_process($port = 8088) {
    $server_script = __FILE__;
    $php_executable = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
    $cmd = $php_executable . " $server_script $port > /dev/null 2>&1 & echo $!";
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