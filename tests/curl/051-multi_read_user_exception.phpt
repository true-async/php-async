--TEST--
Async curl multi: exception in CURLOPT_READFUNCTION propagates to curl_multi_exec
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
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/put");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILESIZE, 1000);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $infile, $length) {
        throw new RuntimeException("multi read callback error");
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

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
caught: multi read callback error
Done
