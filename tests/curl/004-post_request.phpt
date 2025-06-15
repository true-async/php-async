--TEST--
Async cURL POST request
--EXTENSIONS--
curl
--FILE--
<?php
include "../../sapi/cli/tests/php_cli_server.inc";

use function Async\spawn;
use function Async\await;

php_cli_server_start();

function test_post_request() {
    echo "Starting POST test\n";
    
    $post_data = "test=data&value=123";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://" . PHP_CLI_SERVER_ADDRESS);
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

echo "Test end\n";
?>
--EXPECT--
Test start
Starting POST test
HTTP Code: 200
Error: none
Response: Hello world
Test end