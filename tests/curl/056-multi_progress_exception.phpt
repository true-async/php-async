--TEST--
Async curl multi: exception in CURLOPT_XFERINFOFUNCTION propagates
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $call_count = 0;
    $thrown = false;

    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function($ch, $dltotal, $dlnow, $ultotal, $ulnow) use (&$call_count, &$thrown) {
        $call_count++;
        if ($call_count >= 2 && !$thrown) {
            $thrown = true;
            throw new RuntimeException("multi progress error");
        }
        return 0;
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
caught: multi progress error
Done
