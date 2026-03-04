--TEST--
Async curl multi: CURLOPT_FILE to broken pipe triggers write error
--EXTENSIONS--
curl
--INI--
error_reporting=E_ALL & ~E_NOTICE
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    // Create a socket pair, close the read end -> writes get EPIPE
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $fp = $pair[0];
    fclose($pair[1]);

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

    while ($info = curl_multi_info_read($mh)) {
        if ($info['msg'] === CURLMSG_DONE) {
            $errno = $info['result'];
            echo "Transfer result: " . ($errno === CURLE_WRITE_ERROR ? "CURLE_WRITE_ERROR" : "errno=$errno") . "\n";
        }
    }

    $errno = curl_errno($ch);
    echo "curl_errno: " . ($errno === CURLE_WRITE_ERROR ? "CURLE_WRITE_ERROR" : "errno=$errno") . "\n";

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    fclose($fp);
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Transfer result: CURLE_WRITE_ERROR
curl_errno: CURLE_WRITE_ERROR
Done
