--TEST--
Async curl multi: CURLOPT_FILE downloads response to file in multi mode
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$tmpfile1 = tempnam(sys_get_temp_dir(), 'curl_multi_file1_');
$tmpfile2 = tempnam(sys_get_temp_dir(), 'curl_multi_file2_');

$coroutine = spawn(function() use ($server, $tmpfile1, $tmpfile2) {
    $fp1 = fopen($tmpfile1, 'w');
    $fp2 = fopen($tmpfile2, 'w');

    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_FILE, $fp1);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_FILE, $fp2);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $errno1 = curl_errno($ch1);
    $errno2 = curl_errno($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    fclose($fp1);
    fclose($fp2);

    echo "errno1: $errno1\n";
    echo "errno2: $errno2\n";
});

await($coroutine);

echo "File 1: " . file_get_contents($tmpfile1) . "\n";
echo "File 2: " . file_get_contents($tmpfile2) . "\n";

@unlink($tmpfile1);
@unlink($tmpfile2);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
errno1: 0
errno2: 0
File 1: Hello World
File 2: {"message":"Hello JSON","status":"ok"}
Done
