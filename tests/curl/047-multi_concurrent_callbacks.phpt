--TEST--
Async curl multi: two coroutines with different callback modes (WRITEFUNCTION vs FILE)
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

$tmpfile = tempnam(sys_get_temp_dir(), 'curl_multi_coro_');

// Coroutine 1: curl_multi with WRITEFUNCTION callbacks
$c1 = spawn(function() use ($server) {
    $data1 = '';
    $data2 = '';

    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch1, CURLOPT_WRITEFUNCTION, function($ch, $d) use (&$data1) {
        $data1 .= $d;
        return strlen($d);
    });

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch2, CURLOPT_WRITEFUNCTION, function($ch, $d) use (&$data2) {
        $data2 .= $d;
        return strlen($d);
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

    return ['data1' => $data1, 'data2' => $data2];
});

// Coroutine 2: curl_multi with CURLOPT_FILE
$c2 = spawn(function() use ($server, $tmpfile) {
    $fp = fopen($tmpfile, 'w');

    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    curl_multi_add_handle($mh, $ch);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $errno = curl_errno($ch);

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    fclose($fp);

    return ['errno' => $errno];
});

[$results, $exceptions] = await_all([$c1, $c2]);

echo "Coro 1 data1: {$results[0]['data1']}\n";
echo "Coro 1 data2: {$results[0]['data2']}\n";
echo "Coro 2 errno: {$results[1]['errno']}\n";

$file_content = file_get_contents($tmpfile);
echo "Coro 2 file size: " . strlen($file_content) . "\n";

echo "Exceptions: " . count(array_filter($exceptions)) . "\n";

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Coro 1 data1: Hello World
Coro 1 data2: {"message":"Hello JSON","status":"ok"}
Coro 2 errno: 0
Coro 2 file size: 10000
Exceptions: 0
Done
