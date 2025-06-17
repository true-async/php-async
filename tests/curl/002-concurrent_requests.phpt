--TEST--
Concurrent async cURL requests
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\awaitAll;

$server = async_test_server_start();

function make_request($id, $server) {
    echo "Request $id: starting\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
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
    spawn(fn() => make_request(1, $server)),
    spawn(fn() => make_request(2, $server)),
    spawn(fn() => make_request(3, $server)),
];

$results = awaitAll($coroutines);

foreach ($results as $result) {
    echo "Result: $result\n";
}

echo "Test end\n";

async_test_server_stop($server);
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
Result: Request 2 result: Hello World
Result: Request 3 result: Hello World
Test end