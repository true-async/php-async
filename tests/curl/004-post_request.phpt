--TEST--
Async cURL POST request
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

function test_post_request($server) {
    echo "Starting POST test\n";
    
    $post_data = "test=data&value=123";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/post");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response: $response\n";
    
    return $response;
}

echo "Test start\n";

$coroutine = spawn(fn() => test_post_request($server));
$result = await($coroutine);

echo "Test end\n";

async_test_server_stop($server);
?>
--EXPECTF--
Test start
Starting POST test
HTTP Code: 200
Error: none
Response: %s
Test end