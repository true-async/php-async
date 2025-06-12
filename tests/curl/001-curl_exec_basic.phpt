--TEST--
Basic async curl_exec GET request
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\await;

// Start test server
$server_pid = start_test_server_process(8088);

function test_basic_get() {
    echo "Starting basic GET test\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/'));
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

$coroutine = spawn(test_basic_get(...));
$result = await($coroutine);

// Stop server
stop_test_server_process($server_pid);

echo "Test end\n";
?>
--EXPECT--
Test start
Starting basic GET test
HTTP Code: 200
Error: none
Response: Hello World
Test end