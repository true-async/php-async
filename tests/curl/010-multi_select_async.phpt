--TEST--
cURL multi select with async operations
--EXTENSIONS--
curl
--FILE--
<?php
include "../common/simple_http_server.php";

use function Async\spawn;
use function Async\await;

// Start test server
$server_pid = start_test_server_process(8088);

function test_curl_multi() {
    echo "coroutine start\n";

    $mh = curl_multi_init();

    // First cURL handle
    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, get_test_server_url('/'));
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_multi_add_handle($mh, $ch1);

    // Second cURL handle
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, get_test_server_url('/json'));
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

    var_dump($response1);
    var_dump($response2);

    echo "coroutine end\n";
}

function test_simple() {
    echo "coroutine 2\n";
}

echo "start\n";

$coroutine1 = spawn(test_curl_multi(...));
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
string(40) "{\"message\":\"Hello JSON\",\"status\":\"ok\"}"
coroutine end
end