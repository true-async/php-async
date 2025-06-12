--TEST--
Mixed sync and async cURL operations
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\await;

// Start test server
$server_pid = start_test_server_process(8088);

function sync_request() {
    echo "Sync request start\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "Sync request complete: HTTP $http_code\n";
    return $response;
}

function async_request() {
    echo "Async request start\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "Async request complete: HTTP $http_code\n";
    return $response;
}

echo "Test start\n";

// First, make a synchronous request (not in coroutine)
$sync_result = sync_request();
echo "Sync result: $sync_result\n";

// Then make an async request (in coroutine)
$async_coroutine = spawn(async_request(...));
$async_result = await($async_coroutine);
echo "Async result: $async_result\n";

// Mix them together
echo "Mixed execution start\n";
$mixed_coroutine = spawn(function() {
    echo "In coroutine: making async request\n";
    return async_request();
});

// Make sync request while coroutine is running
echo "Making sync request while coroutine runs\n";
$sync_while_async = sync_request();

$mixed_result = await($mixed_coroutine);

echo "Sync while async: $sync_while_async\n";
echo "Mixed async result: $mixed_result\n";

// Stop server
stop_test_server_process($server_pid);

echo "Test end\n";
?>
--EXPECT--
Test start
Sync request start
Sync request complete: HTTP 200
Sync result: Hello World
Async request start
Async request complete: HTTP 200
Async result: {"message":"Hello JSON","status":"ok"}
Mixed execution start
In coroutine: making async request
Making sync request while coroutine runs
Async request start
Sync request start
Sync request complete: HTTP 200
Async request complete: HTTP 200
Sync while async: Hello World
Mixed async result: {"message":"Hello JSON","status":"ok"}
Test end