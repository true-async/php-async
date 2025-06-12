--TEST--
Async cURL POST request
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\await;

// Start test server
$server_pid = start_test_server_process(8088);

function test_post_request() {
    echo "Starting POST test\n";
    
    $post_data = "test=data&value=123";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/post'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
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

echo "Test start\n";

$coroutine = spawn(test_post_request(...));
$result = await($coroutine);

// Stop server
stop_test_server_process($server_pid);

echo "Test end\n";
?>
--EXPECT--
Test start
Starting POST test
HTTP Code: 200
Error: none
Response: POST received: 17 bytes
Test end