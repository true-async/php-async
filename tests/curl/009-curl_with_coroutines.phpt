--TEST--
cURL with async coroutines
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;
use function Async\await_all;

$server = async_test_server_start();

function make_curl_request($server, $id) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    
    return [
        'id' => $id,
        'response' => $response,
        'http_code' => $http_code,
        'start_msg' => "Coroutine $id: starting",
        'complete_msg' => "Coroutine $id: completed (HTTP $http_code)"
    ];
}

echo "Test start\n";

// Test basic coroutine usage with cURL
$coroutines = [
    spawn(fn() => make_curl_request($server, 1)),
    spawn(fn() => make_curl_request($server, 2)),
    spawn(fn() => make_curl_request($server, 3))
];

[$results, $exceptions] = await_all($coroutines);

// Collect and sort messages
$start_messages = [];
$complete_messages = [];
$result_messages = [];

foreach ($results as $result) {
    $start_messages[] = $result['start_msg'];
    $complete_messages[] = $result['complete_msg'];
    $result_messages[] = "Result {$result['id']}: {$result['response']}";
}

// Sort all messages by ID
sort($start_messages);
sort($complete_messages);
sort($result_messages);

// Output in consistent order
foreach ($start_messages as $msg) {
    echo $msg . "\n";
}
foreach ($complete_messages as $msg) {
    echo $msg . "\n";
}
foreach ($result_messages as $msg) {
    echo $msg . "\n";
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