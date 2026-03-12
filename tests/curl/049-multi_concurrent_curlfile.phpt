--TEST--
Async curl multi: two coroutines each uploading CURLFile via curl_multi
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

$tmpfile1 = tempnam(sys_get_temp_dir(), 'curl_coro_up1_');
$tmpfile2 = tempnam(sys_get_temp_dir(), 'curl_coro_up2_');
file_put_contents($tmpfile1, 'coroutine one file data');
file_put_contents($tmpfile2, 'coroutine two file data here');

// Coroutine 1: upload 2 files via curl_multi
$c1 = spawn(function() use ($server, $tmpfile1) {
    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch1, CURLOPT_SAFE_UPLOAD, true);
    curl_setopt($ch1, CURLOPT_POSTFIELDS, ['file' => curl_file_create($tmpfile1, 'text/plain', 'from_coro1.txt')]);

    curl_multi_add_handle($mh, $ch1);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $r = curl_multi_getcontent($ch1);
    curl_multi_remove_handle($mh, $ch1);
    curl_multi_close($mh);

    return ['coro' => 1, 'result' => $r];
});

// Coroutine 2: upload 2 files via curl_multi
$c2 = spawn(function() use ($server, $tmpfile2) {
    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch1, CURLOPT_SAFE_UPLOAD, true);
    curl_setopt($ch1, CURLOPT_POSTFIELDS, ['file' => curl_file_create($tmpfile2, 'text/plain', 'from_coro2.txt')]);

    curl_multi_add_handle($mh, $ch1);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $r = curl_multi_getcontent($ch1);
    curl_multi_remove_handle($mh, $ch1);
    curl_multi_close($mh);

    return ['coro' => 2, 'result' => $r];
});

[$results, $exceptions] = await_all([$c1, $c2]);

usort($results, fn($a, $b) => $a['coro'] - $b['coro']);

echo "Coro 1: {$results[0]['result']}\n";
echo "Coro 2: {$results[1]['result']}\n";
echo "Exceptions: " . count(array_filter($exceptions)) . "\n";

@unlink($tmpfile1);
@unlink($tmpfile2);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Coro 1: from_coro1.txt|text/plain|23
Coro 2: from_coro2.txt|text/plain|28
Exceptions: 0
Done
