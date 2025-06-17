--TEST--
cURL multi select with async operations
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

function test_curl_multi($server) {
    echo "coroutine start\n";

    $mh = curl_multi_init();

    // First cURL handle
    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_multi_add_handle($mh, $ch1);

    // Second cURL handle
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    curl_multi_add_handle($mh, $ch2);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) {
            echo "Error: " . curl_multi_strerror($status) . "\n";
            break;
        }

        if ($active > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active > 0);

    // Retrieve responses
    $response1 = curl_multi_getcontent($ch1);
    $response2 = curl_multi_getcontent($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    curl_close($ch1);
    curl_close($ch2);

    echo "Response 1: $response1\n";
    echo "Response 2: $response2\n";

    echo "coroutine end\n";
}

function test_simple() {
    echo "coroutine 2\n";
}

echo "start\n";

$coroutine1 = spawn(fn() => test_curl_multi($server));
$coroutine2 = spawn(fn() => test_simple());

await($coroutine1);
await($coroutine2);

echo "end\n";

async_test_server_stop($server);
?>
--EXPECT--
start
coroutine start
coroutine 2
Response 1: Hello World
Response 2: {"message":"Hello JSON","status":"ok"}
coroutine end
end