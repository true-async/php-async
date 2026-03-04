--TEST--
Async curl multi: CURLOPT_WRITEFUNCTION callback in multi mode
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $mh = curl_multi_init();

    $received1 = '';
    $received2 = '';

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch1, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$received1) {
        $received1 .= $data;
        return strlen($data);
    });

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch2, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$received2) {
        $received2 .= $data;
        return strlen($data);
    });

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    echo "Received 1: $received1\n";
    echo "Received 2: $received2\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Received 1: Hello World
Received 2: {"message":"Hello JSON","status":"ok"}
Done
