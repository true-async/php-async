--TEST--
curl_multi_exec loop without curl_multi_select and without coroutines
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

$server = async_test_server_start();

$mh = curl_multi_init();

$ch1 = curl_init();
curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch1);

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch2);

// Loop with curl_multi_exec only — no curl_multi_select, no coroutines
$active = null;
$iterations = 0;
do {
    $status = curl_multi_exec($mh, $active);
    $iterations++;

    if ($status !== CURLM_OK) {
        echo "Error: " . curl_multi_strerror($status) . "\n";
        break;
    }
} while ($active > 0);

$r1 = curl_multi_getcontent($ch1);
$r2 = curl_multi_getcontent($ch2);

curl_multi_remove_handle($mh, $ch1);
curl_multi_remove_handle($mh, $ch2);
curl_multi_close($mh);

echo "Response 1: $r1\n";
echo "Response 2: $r2\n";
echo "Iterations: " . ($iterations > 0 ? 'ok' : 'none') . "\n";

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECT--
Response 1: Hello World
Response 2: Hello World
Iterations: ok
Done
