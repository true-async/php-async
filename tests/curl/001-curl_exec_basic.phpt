--TEST--
Basic async curl_exec GET request with comprehensive testing
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;
use function Async\await_all;

// Start our test server
$server = async_test_server_start();
echo "Test server started on localhost:{$server->port}\n";

function test_basic_get($server) {
    echo "Starting basic GET test\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, "AsyncTest/1.0");
    
    $start_time = microtime(true);
    $response = curl_exec($ch);
    $end_time = microtime(true);
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $error = curl_error($ch);
    
    
    $duration = round(($end_time - $start_time) * 1000, 2);
    
    echo "HTTP Code: $http_code\n";
    echo "Content-Type: $content_type\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Duration: {$duration}ms\n";
    echo "Response: $response\n";
    
    // Validate response
    if ($http_code !== 200) {
        throw new Exception("Expected HTTP 200, got $http_code");
    }
    if ($response !== "Hello World") {
        throw new Exception("Unexpected response: $response");
    }
    
    return $response;
}

function test_json_endpoint($server) {
    echo "Testing JSON endpoint\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    
    echo "JSON HTTP Code: $http_code\n";
    echo "JSON Content-Type: $content_type\n";
    
    $data = json_decode($response, true);
    if ($data === null) {
        throw new Exception("Invalid JSON response");
    }
    
    echo "JSON Message: {$data['message']}\n";
    echo "JSON Status: {$data['status']}\n";
    
    return $data;
}

function test_error_handling($server) {
    echo "Testing error handling\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/error");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    
    echo "Error HTTP Code: $http_code\n";
    echo "Error Response: $response\n";
    
    if ($http_code !== 500) {
        throw new Exception("Expected HTTP 500, got $http_code");
    }
    
    return $http_code;
}

echo "Test start\n";

// Run multiple async tests concurrently
$coroutines = [
    spawn(fn() => test_basic_get($server)),
    spawn(fn() => test_json_endpoint($server)),
    spawn(fn() => test_error_handling($server))
];

$results = await_all($coroutines);

echo "All tests completed successfully\n";
echo "Test end\n";

// Clean up server
async_test_server_stop($server);
?>
--EXPECTF--
Test server started on localhost:%d
Test start
Starting basic GET test
Testing JSON endpoint
Testing error handling
HTTP Code: 200
Content-Type: text/html; charset=UTF-8
Error: none
Duration: %fms
Response: Hello World
JSON HTTP Code: 200
JSON Content-Type: application/json
JSON Message: Hello JSON
JSON Status: ok
Error HTTP Code: 500
Error Response: Internal Server Error
All tests completed successfully
Test end