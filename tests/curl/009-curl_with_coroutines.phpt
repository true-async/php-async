--TEST--
cURL with async coroutines
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;
use function Async\awaitAll;

$server = async_test_server_start();

function make_curl_request($server, $id) {
    echo "Coroutine $id: starting\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    echo "Coroutine $id: completed (HTTP $http_code)\n";
    
    return [
        'id' => $id,
        'response' => $response,
        'http_code' => $http_code
    ];
}

echo "Test start\n";

// Test basic coroutine usage with cURL
$coroutines = [
    spawn(fn() => make_curl_request($server, 1)),
    spawn(fn() => make_curl_request($server, 2)),
    spawn(fn() => make_curl_request($server, 3))
];

$results = awaitAll($coroutines);

foreach ($results as $result) {
    echo "Result {$result['id']}: {$result['response']}\n";
}

echo "Test end\n";

async_test_server_stop($server);
?>
--EXPECT--
Test start
Coroutine 1: starting
Coroutine 2: starting
Coroutine 3: starting
Coroutine 1: completed (HTTP 200)
Coroutine 2: completed (HTTP 200)
Coroutine 3: completed (HTTP 200)
Result 1: Hello World
Result 2: Hello World
Result 3: Hello World
Test end