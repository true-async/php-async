--TEST--
Async cURL error handling
--EXTENSIONS--
curl
--FILE--
<?php
include "../../sapi/cli/tests/php_cli_server.inc";

use function Async\spawn;
use function Async\awaitAll;

php_cli_server_start();

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
    
    // Test with invalid URL to trigger an error
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://" . PHP_CLI_SERVER_ADDRESS . "/nonexistent.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response length: " . strlen($response) . "\n";
    
    return $response;
}

function test_not_found() {
    echo "Testing 404 error\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://" . PHP_CLI_SERVER_ADDRESS . "/missing.html");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "HTTP Code: $http_code\n";
    echo "Response length: " . strlen($response) . "\n";
    
    return $response;
}

echo "Test start\n";

$coroutines = [
    spawn(test_connection_error(...)),
    spawn(test_server_error(...)),
    spawn(test_not_found(...)),
];

$results = awaitAll($coroutines);

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
HTTP Code: 404
Error: none
Response length: %d
HTTP Code: 404
Response length: %d
Test end