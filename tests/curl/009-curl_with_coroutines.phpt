--TEST--
cURL exec with coroutine switching
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\await;

// Start test server
$server_pid = start_test_server_process(8088);

function test_curl() {
    echo "coroutine start\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, get_test_server_url('/'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    curl_close($ch);

    var_dump($response);
    echo "coroutine end\n";
}

function test_simple() {
    echo "coroutine 2\n";
}

echo "start\n";

$coroutine1 = spawn(test_curl(...));
$coroutine2 = spawn(test_simple(...));

await($coroutine1);
await($coroutine2);

// Stop server
stop_test_server_process($server_pid);

echo "end\n";
?>
--EXPECT--
start
coroutine start
coroutine 2
string(11) "Hello World"
coroutine end
end