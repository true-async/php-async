--TEST--
Async curl multi: CURLFile upload via curl_multi
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$tmpfile = tempnam(sys_get_temp_dir(), 'curl_multi_upload_');
file_put_contents($tmpfile, 'hello world from multi');

$coroutine = spawn(function() use ($server, $tmpfile) {
    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);

    $file = curl_file_create($tmpfile, 'text/plain', 'test.txt');
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $file]);

    curl_multi_add_handle($mh, $ch);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) {
            echo "Multi exec error: " . curl_multi_strerror($status) . "\n";
            break;
        }
        if ($active > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active > 0);

    $response = curl_multi_getcontent($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);

    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response: $response\n";
});

await($coroutine);

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
HTTP Code: 200
Error: none
Response: test.txt|text/plain|22
Done
