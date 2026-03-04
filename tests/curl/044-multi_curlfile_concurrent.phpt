--TEST--
Async curl multi: multiple concurrent CURLFile uploads
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$tmpfile1 = tempnam(sys_get_temp_dir(), 'curl_multi_up1_');
$tmpfile2 = tempnam(sys_get_temp_dir(), 'curl_multi_up2_');
$tmpfile3 = tempnam(sys_get_temp_dir(), 'curl_multi_up3_');
file_put_contents($tmpfile1, 'file one content');
file_put_contents($tmpfile2, 'file two');
file_put_contents($tmpfile3, 'file three data here');

$coroutine = spawn(function() use ($server, $tmpfile1, $tmpfile2, $tmpfile3) {
    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch1, CURLOPT_SAFE_UPLOAD, true);
    curl_setopt($ch1, CURLOPT_POSTFIELDS, ['file' => curl_file_create($tmpfile1, 'text/plain', 'one.txt')]);

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch2, CURLOPT_SAFE_UPLOAD, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, ['file' => curl_file_create($tmpfile2, 'text/plain', 'two.txt')]);

    $ch3 = curl_init();
    curl_setopt($ch3, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch3, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch3, CURLOPT_SAFE_UPLOAD, true);
    curl_setopt($ch3, CURLOPT_POSTFIELDS, ['file' => curl_file_create($tmpfile3, 'text/plain', 'three.txt')]);

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);
    curl_multi_add_handle($mh, $ch3);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $r1 = curl_multi_getcontent($ch1);
    $r2 = curl_multi_getcontent($ch2);
    $r3 = curl_multi_getcontent($ch3);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_remove_handle($mh, $ch3);
    curl_multi_close($mh);

    echo "Upload 1: $r1\n";
    echo "Upload 2: $r2\n";
    echo "Upload 3: $r3\n";
});

await($coroutine);

@unlink($tmpfile1);
@unlink($tmpfile2);
@unlink($tmpfile3);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Upload 1: one.txt|text/plain|16
Upload 2: two.txt|text/plain|8
Upload 3: three.txt|text/plain|20
Done
