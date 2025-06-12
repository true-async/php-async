--TEST--
Async cURL timeout handling
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\await;

// Start test server
$server_pid = start_test_server_process(8088);

function test_timeout() {
    echo "Testing timeout\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/very-slow')); // 2 second response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1); // 1 second timeout
    
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

function test_normal_request() {
    echo "Testing normal request\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/'));
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

$timeout_coroutine = spawn(test_timeout(...));
$normal_coroutine = spawn(test_normal_request(...));

$timeout_result = await($timeout_coroutine);
$normal_result = await($normal_coroutine);

// Stop server
stop_test_server_process($server_pid);

echo "Test end\n";
?>
--EXPECTF--
Test start
Testing timeout
Testing normal request
Duration: %f seconds
Response: timeout
Error present: yes
Error number: %d
HTTP Code: 0
Response: Hello World
Error: none
Test end