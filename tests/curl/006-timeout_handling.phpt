--TEST--
Async cURL timeout handling
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

function test_timeout() {
    echo "Testing timeout\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://192.0.2.1/timeout"); // Non-routable IP for timeout
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1); // 1 second timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    
    $start_time = microtime(true);
    $response = curl_exec($ch);
    $end_time = microtime(true);
    
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    $duration = $end_time - $start_time;
    
    echo "Duration: " . round($duration, 2) . " seconds\n";
    echo "Response: " . ($response ? "received" : "timeout") . "\n";
    echo "Error present: " . (!empty($error) ? "yes" : "no") . "\n";
    echo "Error number: $errno\n";
    echo "HTTP Code: $http_code\n";
    
    return $response;
}

function test_normal_request($server) {
    echo "Testing normal request\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    echo "Response: $response\n";
    echo "Error: " . ($error ?: "none") . "\n";
    
    return $response;
}

echo "Test start\n";

$timeout_coroutine = spawn(fn() => test_timeout());
$normal_coroutine = spawn(fn() => test_normal_request($server));

$timeout_result = await($timeout_coroutine);
$normal_result = await($normal_coroutine);

echo "Test end\n";

async_test_server_stop($server);
?>
--EXPECTF--
Test start
Testing timeout
Testing normal request
Response: Hello World
Error: none
Duration: %f seconds
Response: timeout
Error present: yes
Error number: %d
HTTP Code: 0
Test end