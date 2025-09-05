--TEST--
Async cURL error handling
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\awaitAll;

$server = async_test_server_start();

function test_connection_error() {
    echo "Testing connection error\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:99991/nonexistent"); // Wrong port
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    
    
    echo "Connection failed as expected\n";
    echo "Error present: " . (!empty($error) ? "yes" : "no") . "\n";
    echo "Error number: $errno\n";
    
    return $response;
}

function test_server_error($server) {
    echo "Testing server error\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/error");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response length: " . strlen($response) . "\n";
    
    return $response;
}

function test_not_found($server) {
    echo "Testing 404 error\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/missing.html");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    
    echo "HTTP Code: $http_code\n";
    echo "Response length: " . strlen($response) . "\n";
    
    return $response;
}

echo "Test start\n";

$coroutines = [
    spawn(fn() => test_connection_error()),
    spawn(fn() => test_server_error($server)),
    spawn(fn() => test_not_found($server)),
];

$results = awaitAll($coroutines);

echo "Test end\n";

async_test_server_stop($server);
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
Response length: %d
HTTP Code: 404
Response length: %d
Test end