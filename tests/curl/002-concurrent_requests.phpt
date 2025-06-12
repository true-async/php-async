--TEST--
Concurrent async cURL requests
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\awaitAll;

// Start test server
$server_pid = start_test_server_process(8088);

function make_request($url, $id) {
    echo "Request $id: starting\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "Request $id: completed (HTTP $http_code)\n";
    return "Request $id result: $response";
}

echo "Test start\n";

// Launch multiple concurrent requests
$coroutines = [
    spawn(fn() => make_request(get_test_server_url('/'), 1)),
    spawn(fn() => make_request(get_test_server_url('/json'), 2)),
    spawn(fn() => make_request(get_test_server_url('/slow'), 3)),
];

$results = awaitAll($coroutines);

foreach ($results as $result) {
    echo "Result: $result\n";
}

// Stop server
stop_test_server_process($server_pid);

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
Result: Request 1 result: Hello World
Result: Request 2 result: {"message":"Hello JSON","status":"ok"}
Result: Request 3 result: Slow Response
Test end