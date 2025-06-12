--TEST--
Async cURL large response handling
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\await;

// Start test server
$server_pid = start_test_server_process(8088);

function test_large_response() {
    echo "Testing large response\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/large')); // 10KB response
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
    echo "Download size: $download_size bytes\n";
    echo "Duration: " . round($duration, 2) . "ms\n";
    echo "Response starts with: " . substr($response, 0, 20) . "...\n";
    echo "Response ends with: ..." . substr($response, -20) . "\n";
    
    return strlen($response);
}

echo "Test start\n";

$coroutine = spawn(test_large_response(...));
$size = await($coroutine);

// Stop server
stop_test_server_process($server_pid);

echo "Test end\n";
?>
--EXPECTF--
Test start
Testing large response
HTTP Code: 200
Error: none
Response length: 10000 bytes
Download size: %f
Duration: %fms
Response starts with: ABCDEFGHIJABCDEFGHIJ...
Response ends with: ...ABCDEFGHIJABCDEFGHIJ
Test end