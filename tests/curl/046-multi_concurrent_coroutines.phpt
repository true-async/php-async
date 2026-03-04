--TEST--
Async curl multi: two coroutines each with own curl_multi handle
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

$c1 = spawn(function() use ($server) {
    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $r1 = curl_multi_getcontent($ch1);
    $r2 = curl_multi_getcontent($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    return ['coro' => 1, 'r1' => $r1, 'r2' => $r2];
});

$c2 = spawn(function() use ($server) {
    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $r1 = curl_multi_getcontent($ch1);
    $r2 = curl_multi_getcontent($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    return ['coro' => 2, 'r1_len' => strlen($r1), 'r2' => $r2];
});

[$results, $exceptions] = await_all([$c1, $c2]);

usort($results, fn($a, $b) => $a['coro'] - $b['coro']);

echo "Coro 1: r1={$results[0]['r1']}, r2={$results[0]['r2']}\n";
echo "Coro 2: r1_len={$results[1]['r1_len']}, r2={$results[1]['r2']}\n";
echo "Exceptions: " . count(array_filter($exceptions)) . "\n";

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Coro 1: r1=Hello World, r2={"message":"Hello JSON","status":"ok"}
Coro 2: r1_len=10000, r2=Hello World
Exceptions: 0
Done
