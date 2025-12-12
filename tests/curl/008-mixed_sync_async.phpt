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
$output = [];

function sync_request($server) {
    global $output;
    $output['0'] = "Sync request start";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);


    $output['1'] = "Sync request complete: HTTP $http_code";
    return $response;
}

function async_request($server) {
    global $output;
    $output['2'] = "Async request start";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);


    $output['3'] = "Async request complete: HTTP $http_code";
    return $response;
}

$output['4'] = "Test start";

// First, make a synchronous request (not in coroutine)
$sync_result = sync_request($server);
$output['5'] = "Sync result: $sync_result";

// Then make an async request (in coroutine)
$async_coroutine = spawn(fn() => async_request($server));
$async_result = await($async_coroutine);
$output['6'] = "Async result: $async_result";

// Mix them together
$output['7'] = "Mixed execution start";
$mixed_coroutine = spawn(function() use ($server) {
    global $output;
    $output['8'] = "In coroutine: making async request";
    return async_request($server);
});

// Make sync request while coroutine is running
$output['9'] = "Making sync request while coroutine runs";
$sync_while_async = sync_request($server);

$mixed_result = await($mixed_coroutine);

$output['10'] = "Sync while async: $sync_while_async";
$output['11'] = "Mixed async result: $mixed_result";

$output['12'] = "Test end";

// Sort and output
ksort($output);
foreach ($output as $msg) {
    echo "$msg\n";
}

async_test_server_stop($server);
?>
--EXPECT--
Sync request start
Sync request complete: HTTP 200
Async request start
Async request complete: HTTP 200
Test start
Sync result: Hello World
Async result: Hello World
Mixed execution start
In coroutine: making async request
Making sync request while coroutine runs
Sync while async: Hello World
Mixed async result: Hello World
Test end