--TEST--
Async cURL large response handling
--EXTENSIONS--
curl
--FILE--
<?php
include "../../sapi/cli/tests/php_cli_server.inc";

use function Async\spawn;
use function Async\await;

php_cli_server_start();

function test_large_response() {
    echo "Testing response handling\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://" . PHP_CLI_SERVER_ADDRESS);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $start_time = microtime(true);
    $response = curl_exec($ch);
    $end_time = microtime(true);
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $download_size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    
    curl_close($ch);
    
    $duration = ($end_time - $start_time) * 1000; // Convert to ms
    
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response length: " . strlen($response) . " bytes\n";
    echo "Duration: " . round($duration, 2) . "ms\n";
    echo "Response: $response\n";
    
    return strlen($response);
}

echo "Test start\n";

$coroutine = spawn(test_large_response(...));
$size = await($coroutine);


echo "Test end\n";
?>
--EXPECTF--
Test start
Testing response handling
HTTP Code: 200
Error: none
Response length: %d bytes
Duration: %fms
Response: Hello world
Test end