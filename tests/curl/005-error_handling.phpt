--TEST--
Async cURL error handling
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

function test_connection_error() {
    $output = [];
    $output[1] = "Testing connection error";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:99991/nonexistent"); // Wrong port
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);


    $output[4] = "Connection failed as expected";
    $output[5] = "Error present: " . (!empty($error) ? "yes" : "no");
    $output[6] = "Error number: $errno";

    return $output;
}

function test_server_error($server) {
    $output = [];
    $output[2] = "Testing server error";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/error");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);


    $output[7] = "HTTP Code: $http_code";
    $output[8] = "Error: " . ($error ?: "none");
    $output[9] = "Response length: " . strlen($response);

    return $output;
}

function test_not_found($server) {
    $output = [];
    $output[3] = "Testing 404 error";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/missing.html");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);


    $output[10] = "HTTP Code: $http_code";
    $output[11] = "Response length: " . strlen($response);

    return $output;
}

echo "Test start\n";

$coroutines = [
    spawn(fn() => test_connection_error()),
    spawn(fn() => test_server_error($server)),
    spawn(fn() => test_not_found($server)),
];

$results = await_all($coroutines);

// Merge all outputs and sort by key to ensure deterministic order
$all_output = [];
foreach ($results as $output) {
    $all_output = array_merge($all_output, $output);
}
ksort($all_output);

// Print in sorted order
foreach ($all_output as $line) {
    echo "$line\n";
}

echo "Test end\n";

async_test_server_stop($server);
?>
--EXPECTF--
Test start
Testing connection error
Testing server error
Testing 404 error
Connection failed as expected
Error present: yes
Error number: %d
HTTP Code: 500
Error: none
Response length: %d
HTTP Code: 404
Response length: %d
Test end