--TEST--
Concurrent async cURL requests
--EXTENSIONS--
curl
--FILE--
<?php
include "../../sapi/cli/tests/php_cli_server.inc";

use function Async\spawn;
use function Async\awaitAll;

php_cli_server_start();

function make_request_with_delay($delay_ms, $id) {
    echo "Request $id: starting\n";
    
    // Add delay before making the request
    usleep($delay_ms * 1000);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://" . PHP_CLI_SERVER_ADDRESS);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "Request $id: completed (HTTP $http_code)\n";
    return "Request $id result: $response";
}

echo "Test start\n";

// Launch multiple concurrent requests with different delays
$coroutines = [
    spawn(fn() => make_request_with_delay(10, 1)),
    spawn(fn() => make_request_with_delay(20, 2)),
    spawn(fn() => make_request_with_delay(30, 3)),
];

$results = awaitAll($coroutines);

foreach ($results as $result) {
    echo "Result: $result\n";
}

echo "Test end\n";
?>
--EXPECTF--
Test start
Request 1: starting
Request 2: starting
Request 3: starting
Request 1: completed (HTTP 200)
Request 2: completed (HTTP 200)
Request 3: completed (HTTP 200)
Result: Request 1 result: Hello world
Result: Request 2 result: Hello world
Result: Request 3 result: Hello world
Test end