--TEST--
Async curl multi: CURLOPT_INFILE PUT request via multi
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$upload_file = tempnam(sys_get_temp_dir(), 'curl_multi_read_');
file_put_contents($upload_file, str_repeat("HELLO", 200)); // 1000 bytes

$coroutine = spawn(function() use ($server, $upload_file) {
    $fp = fopen($upload_file, 'r');

    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/put");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, 1000);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    curl_multi_add_handle($mh, $ch);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $response = curl_multi_getcontent($ch);
    $errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);

    fclose($fp);

    echo "errno: $errno\n";
    echo "HTTP Code: $http_code\n";
    echo "Response: $response\n";
});

await($coroutine);

@unlink($upload_file);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
errno: 0
HTTP Code: 200
Response: PUT received: 1000 bytes
Done
