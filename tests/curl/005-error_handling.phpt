--TEST--
Async cURL error handling
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\awaitAll;

// Start test server
$server_pid = start_test_server_process(8088);

function test_connection_error() {
    echo "Testing connection error\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:9999/nonexistent"); // Wrong port
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    
    curl_close($ch);
    
    echo "Connection failed as expected\n";
    echo "Error present: " . (!empty($error) ? "yes" : "no") . "\n";
    echo "Error number: $errno\n";
    
    return $response;
}

function test_server_error() {
    echo "Testing server error\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/error'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response: $response\n";
    
    return $response;
}

function test_not_found() {
    echo "Testing 404 error\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/nonexistent'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "HTTP Code: $http_code\n";
    echo "Response: $response\n";
    
    return $response;
}

echo "Test start\n";

$coroutines = [
    spawn(test_connection_error(...)),
    spawn(test_server_error(...)),
    spawn(test_not_found(...)),
];

$results = awaitAll($coroutines);

// Stop server
stop_test_server_process($server_pid);

echo "Test end\n";
?>
--EXPECTF--
Test start
Testing connection error
Testing server error
Testing 404 error
Connection failed as expected
Error present: yes
Error number: %d
HTTP Code: 500
Error: none
Response: Internal Server Error
HTTP Code: 404
Response: Not Found
Test end