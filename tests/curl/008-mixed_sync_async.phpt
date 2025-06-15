--TEST--
Mixed sync and async cURL operations
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

function sync_request($server) {
    echo "Sync request start\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "Sync request complete: HTTP $http_code\n";
    return $response;
}

function async_request($server) {
    echo "Async request start\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
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
$sync_result = sync_request($server);
echo "Sync result: $sync_result\n";

// Then make an async request (in coroutine)
$async_coroutine = spawn(fn() => async_request($server));
$async_result = await($async_coroutine);
echo "Async result: $async_result\n";

// Mix them together
echo "Mixed execution start\n";
$mixed_coroutine = spawn(function() use ($server) {
    echo "In coroutine: making async request\n";
    return async_request($server);
});

// Make sync request while coroutine is running
echo "Making sync request while coroutine runs\n";
$sync_while_async = sync_request($server);

$mixed_result = await($mixed_coroutine);

echo "Sync while async: $sync_while_async\n";
echo "Mixed async result: $mixed_result\n";

echo "Test end\n";

async_test_server_stop($server);
?>
--EXPECT--
Test start
Sync request start
Sync request complete: HTTP 200
Sync result: Hello World
Async request start
Async request complete: HTTP 200
Async result: Hello World
Mixed execution start
Making sync request while coroutine runs
Sync request start
In coroutine: making async request
Async request start
Sync request complete: HTTP 200
Async request complete: HTTP 200
Sync while async: Hello World
Mixed async result: Hello World
Test end