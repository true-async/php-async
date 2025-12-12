--TEST--
Async cURL large response handling
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

function test_large_response($server) {
    echo "Testing large response\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response length: " . strlen($response) . " bytes\n";
    
    if ($http_code !== 200) {
        throw new Exception("Expected HTTP 200, got $http_code");
    }
    
    return strlen($response);
}

echo "Test start\n";

$coroutine = spawn(fn() => test_large_response($server));
$result = await($coroutine);

echo "Downloaded: $result bytes\n";
echo "Test end\n";

async_test_server_stop($server);
?>
--EXPECTF--
Test start
Testing large response
HTTP Code: 200
Error: none
Response length: %d bytes
Downloaded: %d bytes
Test end