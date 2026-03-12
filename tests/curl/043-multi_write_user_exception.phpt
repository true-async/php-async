--TEST--
Async curl multi: exception in CURLOPT_WRITEFUNCTION propagates to curl_multi_exec
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

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        throw new RuntimeException("multi callback error");
    });

    curl_multi_add_handle($mh, $ch);

    try {
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($status !== CURLM_OK) break;
            if ($active > 0) curl_multi_select($mh, 1.0);
        } while ($active > 0);
        echo "no exception\n";
    } catch (RuntimeException $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }

    // curl_multi_info_read may or may not have CURLMSG_DONE here:
    // libcurl does not propagate write callback errors to the transfer's
    // internal state, so CURLMSG_DONE generation depends on whether the
    // server's FIN arrives before curl_multi_socket_action processes the
    // timer — a race condition.  Use curl_errno() as the reliable check.
    $errno = curl_errno($ch);
    echo "curl_errno: " . ($errno === CURLE_WRITE_ERROR ? "CURLE_WRITE_ERROR" : "errno=$errno") . "\n";

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
caught: multi callback error
curl_errno: CURLE_WRITE_ERROR
Done
