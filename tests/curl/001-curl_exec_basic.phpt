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

function test_basic_get($server, &$output) {
    $output[1] = "Starting basic GET test";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, "AsyncTest/1.0");

    $response = curl_exec($ch);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);

    $output[2] = "HTTP Code: $http_code";
    $output[3] = "Content-Type: $content_type";
    $output[4] = "Error: " . ($error ?: "none");
    $output[5] = "Response: $response";

    if ($http_code !== 200) {
        throw new Exception("Expected HTTP 200, got $http_code");
    }
    if ($response !== "Hello World") {
        throw new Exception("Unexpected response: $response");
    }
}

function test_json_endpoint($server, &$output) {
    $output[6] = "Testing JSON endpoint";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    $output[7] = "JSON HTTP Code: $http_code";
    $output[8] = "JSON Content-Type: $content_type";

    $data = json_decode($response, true);
    if ($data === null) {
        throw new Exception("Invalid JSON response");
    }

    $output[9] = "JSON Message: {$data['message']}";
    $output[10] = "JSON Status: {$data['status']}";
}

function test_error_handling($server, &$output) {
    $output[11] = "Testing error handling";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/error");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $output[12] = "Error HTTP Code: $http_code";
    $output[13] = "Error Response: $response";

    if ($http_code !== 500) {
        throw new Exception("Expected HTTP 500, got $http_code");
    }
}

echo "Test start\n";

$output = [];

$coroutines = [
    spawn(function() use ($server, &$output) { test_basic_get($server, $output); }),
    spawn(function() use ($server, &$output) { test_json_endpoint($server, $output); }),
    spawn(function() use ($server, &$output) { test_error_handling($server, $output); }),
];

await_all($coroutines);

ksort($output);
foreach ($output as $line) {
    echo "$line\n";
}

echo "All tests completed successfully\n";
echo "Test end\n";

// Clean up server
async_test_server_stop($server);
?>
--EXPECTF--
Test server started on localhost:%d
Test start
Starting basic GET test
HTTP Code: 200
Content-Type: text/html; charset=UTF-8
Error: none
Response: Hello World
Testing JSON endpoint
JSON HTTP Code: 200
JSON Content-Type: application/json
JSON Message: Hello JSON
JSON Status: ok
Testing error handling
Error HTTP Code: 500
Error Response: Internal Server Error
All tests completed successfully
Test end